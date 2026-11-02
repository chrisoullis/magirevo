@php($changes = $getState())

@if (count($changes) === 0)
    <p class="text-sm text-gray-500">No visible differences from the previous published revision.</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-1 pr-4">Section</th>
                    <th class="py-1 pr-4">Item</th>
                    <th class="py-1 pr-4">Before</th>
                    <th class="py-1">After</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $change)
                    <tr class="border-b align-top">
                        <td class="py-1 pr-4">{{ $change['section'] }}</td>
                        <td class="py-1 pr-4">{{ $change['label'] }}</td>
                        <td class="py-1 pr-4 whitespace-pre-line text-danger-600">{{ $change['before'] ?? '—' }}</td>
                        <td class="py-1 whitespace-pre-line text-success-600">{{ $change['after'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
