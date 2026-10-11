<x-layouts.app>
    <section class="card" aria-labelledby="asset-title">
        <h1 id="asset-title">{{ $asset->asset_number }}</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <dl><dt>種別</dt><dd>{{ $asset->asset_type }}</dd><dt>状態</dt><dd>{{ $asset->status }}</dd><dt>メーカー</dt><dd>{{ $asset->manufacturer ?? '未設定' }}</dd><dt>型番</dt><dd>{{ $asset->model ?? '未設定' }}</dd><dt>シリアル番号</dt><dd>{{ $asset->serial_number ?? '未設定' }}</dd><dt>保証期限</dt><dd>{{ $asset->warranty_until?->format('Y-m-d') ?? '未設定' }}</dd></dl>
        @can('update', $asset)<p><a href="{{ route('assets.edit', $asset) }}">編集する</a></p>@endcan
        @can('update', $asset)
            @if ($activeAssignment)
                <section aria-labelledby="return-title">
                    <h2 id="return-title">端末を返却する</h2>
                    <form method="post" action="{{ route('asset-assignments.update', $activeAssignment) }}">
                        @csrf
                        @method('PUT')
                        <label for="returned_at">返却日</label>
                        <input id="returned_at" type="date" name="returned_at" value="{{ old('returned_at', now()->toDateString()) }}" required>
                        <label for="return_note">返却メモ</label>
                        <input id="return_note" name="note" value="{{ old('note', $activeAssignment->note) }}">
                        <button type="submit">返却を記録する</button>
                    </form>
                </section>
            @elseif ($asset->status === config('opsdeck.asset_status.in_stock'))
                <section aria-labelledby="assignment-title">
                    <h2 id="assignment-title">端末を貸し出す</h2>
                    <form method="post" action="{{ route('asset-assignments.store') }}">
                        @csrf
                        <input type="hidden" name="asset_id" value="{{ $asset->id }}">
                        <label for="employee_id">利用者</label>
                        <select id="employee_id" name="employee_id" required>
                            <option value="">選択してください</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>{{ $employee->name }}</option>
                            @endforeach
                        </select>
                        <label for="assigned_at">割当日</label>
                        <input id="assigned_at" type="date" name="assigned_at" value="{{ old('assigned_at', now()->toDateString()) }}" required>
                        <label for="assignment_note">割当メモ</label>
                        <input id="assignment_note" name="note" value="{{ old('note') }}">
                        <button type="submit">貸出を記録する</button>
                    </form>
                </section>
            @endif
        @endcan
        <h2>割当履歴</h2><table><thead><tr><th>利用者</th><th>割当日</th><th>返却日</th></tr></thead><tbody>@forelse($asset->assignments as $assignment)<tr><td>{{ $assignment->employee->name }}</td><td>{{ $assignment->assigned_at?->format('Y-m-d') }}</td><td>{{ $assignment->returned_at?->format('Y-m-d') ?? '利用中' }}</td></tr>@empty<tr><td colspan="3">割当履歴はありません。</td></tr>@endforelse</tbody></table>
        <p><a href="{{ route('assets.index') }}">端末一覧へ戻る</a></p>
    </section>
</x-layouts.app>
