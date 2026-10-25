<x-layouts.app>
    <section class="card">
        <h1>質問票</h1>
        @can('manageOperationalData', $organization)
            <p><a href="{{ route('questionnaires.create') }}">質問票を登録する</a></p>
        @endcan

        <form method="get" action="{{ route('questionnaires.index') }}">
            <label>
                <input type="checkbox" name="pending" value="1" @checked($filters['pending'] ?? false)>
                未承認回答がある質問票だけ表示
            </label>
            <button type="submit">絞り込む</button>
        </form>

        <table>
            <thead>
                <tr><th>タイトル</th><th>期限</th><th>状態</th><th>未承認回答</th></tr>
            </thead>
            <tbody>
                @forelse ($questionnaires as $questionnaire)
                    <tr>
                        <td><a href="{{ route('questionnaires.show', $questionnaire) }}">{{ $questionnaire->title }}</a></td>
                        <td>{{ $questionnaire->due_at?->format('Y-m-d') ?? '未設定' }}</td>
                        <td>{{ config('opsdeck.questionnaire_status_labels.'.$questionnaire->status, $questionnaire->status) }}</td>
                        <td>未承認回答：{{ $questionnaire->pending_answers_count }}件</td>
                    </tr>
                @empty
                    <tr><td colspan="4">{{ ($filters['pending'] ?? false) ? '未承認回答がある質問票はありません。' : '質問票は未登録です。' }}</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $questionnaires->links() }}
    </section>
</x-layouts.app>
