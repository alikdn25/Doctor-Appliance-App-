{{-- "Service report": text, then photos two in a row with captions (owner's task of November 25). --}}
@if (filled($report['text'] ?? null))
    <div class="report-text">{{ $report['text'] }}</div>
@endif
@if (! empty($report['photos']))
    <table class="report-photos">
        @foreach (array_chunk($report['photos'], 2) as $row)
            <tr>
                @foreach ($row as $photo)
                    <td>
                        <img src="{{ $photo['src'] }}" alt="">
                        @if (filled($photo['caption']))
                            <div class="caption">{{ $photo['caption'] }}</div>
                        @endif
                    </td>
                @endforeach
                @if (count($row) === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>
@endif
