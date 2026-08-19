<?php

namespace Tests\Unit;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FilamentModalSubmitActionTest extends TestCase
{
    #[Test]
    public function edit_action_modal_submit_is_save_not_edit(): void
    {
        $edit = EditAction::make()->color('secondary');
        $submit = $edit->getModalSubmitAction();

        $this->assertNotNull($submit);
        $this->assertSame('submit', $submit->getName());
        $this->assertSame('Save Changes', $submit->getLabel());
        $this->assertSame('primary', $submit->getColor());
    }

    #[Test]
    public function create_action_modal_submit_is_create_not_parent_clone(): void
    {
        $create = CreateAction::make();
        $submit = $create->getModalSubmitAction();

        $this->assertNotNull($submit);
        $this->assertSame('submit', $submit->getName());
        $this->assertSame('Create', $submit->getLabel());
        $this->assertSame('primary', $submit->getColor());
    }
}
