<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>DSCSA Provider Checklist — TracePharma</title>
    @include('marketing.pdf.partials.styles')
</head>
<body>
    @include('marketing.pdf.partials.header', [
        'title' => 'DSCSA Provider Evaluation Checklist',
        'subtitle' => 'Questions to ask any DSCSA traceability provider before you sign. Generated '.now()->toDateString().'.',
    ])

    @foreach ($sections as $heading => $questions)
        <h2>{{ $heading }}</h2>
        @foreach ($questions as $question)
            <p class="question">{{ $question }}</p>
        @endforeach
    @endforeach

    <div class="notes">
        <strong>Notes / provider responses</strong>
        <div class="notes-box"></div>
        <div class="notes-box" style="margin-top: 8px;"></div>
    </div>

    @include('marketing.pdf.partials.footer')
</body>
</html>
