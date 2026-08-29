<?php

namespace App\Support\KnowledgeBase;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\RegexHelper;
use League\CommonMark\Xml\XmlNodeRendererInterface;
use League\Config\ConfigurationAwareInterface;
use League\Config\ConfigurationInterface;

/**
 * Resolves relative doc images via public/docs-media (symlink to docs/workflows/media)
 * instead of Vite::asset, which cannot serve flat-file markdown screenshots.
 */
final class PublicAssetImageRenderer implements ConfigurationAwareInterface, NodeRendererInterface, XmlNodeRendererInterface
{
    private ConfigurationInterface $config;

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Image::assertInstanceOf($node);

        $attrs = $node->data->get('attributes');

        $forbidUnsafeLinks = ! $this->config->get('allow_unsafe_links');
        if ($forbidUnsafeLinks && RegexHelper::isLinkPotentiallyUnsafe($node->getUrl())) {
            $attrs['src'] = '';
        } else {
            $attrs['src'] = $this->resolveSrc($node->getUrl());
        }

        $attrs['alt'] = $this->getAltText($node);

        if (($title = $node->getTitle()) !== null) {
            $attrs['title'] = $title;
        }

        if (str($node->getUrl())->endsWith('.mov')) {
            return new HtmlElement('video', [
                'muted' => 'muted',
                'autoplay' => 'autoplay',
                'loop' => 'loop',
                'class' => 'rounded-md ring-1 ring-gray-950/5 dark:ring-white/10',
            ], new HtmlElement('source', $attrs), true);
        }

        return new HtmlElement('img', $attrs, '', true);
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'image';
    }

    /**
     * @return array<string, scalar>
     */
    public function getXmlAttributes(Node $node): array
    {
        Image::assertInstanceOf($node);

        return [
            'destination' => $node->getUrl(),
            'title' => $node->getTitle() ?? '',
        ];
    }

    private function resolveSrc(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $path = ltrim($url, '/');

        // Markdown in docs/knowledge-base uses media/... → public/docs-media/workflows/...
        if (Str::startsWith($path, 'media/')) {
            return asset('docs-media/workflows/'.Str::after($path, 'media/'));
        }

        if (Str::startsWith($path, 'docs-media/')) {
            return asset($path);
        }

        if (Str::startsWith($url, '/')) {
            return asset(ltrim($url, '/'));
        }

        try {
            return Vite::asset($path);
        } catch (\Throwable) {
            return asset('docs-media/'.$path);
        }
    }

    private function getAltText(Image $node): string
    {
        $altText = '';

        foreach ((new NodeIterator($node)) as $n) {
            if ($n instanceof StringContainerInterface) {
                $altText .= $n->getLiteral();
            } elseif ($n instanceof Newline) {
                $altText .= "\n";
            }
        }

        return $altText;
    }
}
