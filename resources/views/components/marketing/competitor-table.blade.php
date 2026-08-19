@props([
    'rows',
    'competitorLabel' => 'Alternative',
    'oursLabel' => 'TracePharma',
])

<div class="tp-marketing-table overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead>
      <tr>
        <th class="px-4 py-3 text-left font-semibold text-tp-ink">Capability</th>
        <th class="px-4 py-3 text-left font-semibold text-tp-muted">{{ $competitorLabel }}</th>
        <th class="px-4 py-3 text-left font-semibold text-tp-teal-400">{{ $oursLabel }}</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($rows as $row)
        <tr>
          <td class="px-4 py-3 font-medium text-tp-ink">{{ $row['capability'] }}</td>
          <td class="px-4 py-3 text-tp-muted">{{ $row['them'] }}</td>
          <td class="px-4 py-3 text-tp-muted">{{ $row['us'] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
