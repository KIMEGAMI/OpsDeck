<x-layouts.app>
    <section class="card" role="status" aria-label="デモデータに関する注意">
        <strong>読み取り専用デモ</strong>
        <p class="muted">以下は架空のサンプル値です。入力・保存はできず、顧客データ、AI、メール、決済サービスにも接続しません。</p>
    </section>

    <section class="card" aria-labelledby="demo-dashboard-title">
        <h1 id="demo-dashboard-title">{{ $demo['organization_name'] }}のIT運用ダッシュボード</h1>
        <p class="muted">社員・端末・SaaS・契約をまとめ、確認が必要な項目と月次コストを把握する画面の例です。</p>

        <div class="dashboard-metrics" aria-label="デモの主要指標">
            @foreach ($demo['metrics'] as $metric)
                <article class="metric-card">
                    <span class="metric-label">{{ $metric['label'] }}</span>
                    <strong class="metric-value">{{ $metric['value'] }}</strong>
                </article>
            @endforeach
        </div>

        <div class="dashboard-columns">
            <section class="dashboard-panel" aria-labelledby="demo-tasks-title">
                <h2 id="demo-tasks-title">今日やること（サンプル）</h2>
                <ul>
                    @foreach ($demo['tasks'] as $task)
                        <li><strong>{{ $task['title'] }}（{{ $task['count'] }}）</strong><br><span class="muted">{{ $task['description'] }}</span></li>
                    @endforeach
                </ul>
            </section>
            <section class="dashboard-panel" aria-labelledby="demo-cost-title">
                <h2 id="demo-cost-title">経費を見直す手がかり（サンプル）</h2>
                <dl>
                    @foreach ($demo['cost_checks'] as $check)
                        <dt>{{ $check['label'] }}</dt>
                        <dd>{{ $check['value'] }}</dd>
                    @endforeach
                </dl>
                <p class="muted">候補を見つけた後、契約条件と利用状況を人が確認します。ここに削減額の保証や確定額は表示していません。</p>
            </section>
        </div>

        <section class="dashboard-panel" aria-labelledby="demo-import-title">
            <h2 id="demo-import-title">既存の管理表から始める</h2>
            <p>元のExcelを作り直す必要はありません。CSVまたはXLSXをプレビューし、列の対応や新規・更新・確認待ちを見てから反映します。</p>
            <p>このデモ自体はサンプルの登録やImportを行いません。</p>
            <h3>社員CSVの取込前プレビュー（架空データ）</h3>
            <table>
                <thead><tr><th>CSVの列</th><th>OpsDeck項目</th><th>サンプル値</th></tr></thead>
                <tbody>
                    @foreach ($sampleColumns as $column)
                        <tr>
                            <td>{{ $column['header'] }}</td>
                            <td>{{ $column['field'] }}</td>
                            <td>{{ $column['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <h3>Import Preview結果の例（架空データ）</h3>
            <p class="muted">以下は分類の見本です。実際のCSVは処理しておらず、登録・更新・メール送信は行いません。</p>
            <div class="dashboard-metrics" aria-label="プレビュー分類ごとのサンプル件数">
                @foreach ($previewCategories as $category)
                    <article class="metric-card">
                        <span class="metric-label">{{ $category['label'] }}</span>
                        <strong class="metric-value">{{ count($category['rows']) }}件</strong>
                    </article>
                @endforeach
            </div>
            <table>
                <thead><tr><th>判定</th><th>社員番号</th><th>氏名</th><th>メールアドレス</th><th>確認理由</th></tr></thead>
                @foreach ($previewCategories as $category)
                    <tbody>
                        <tr><th colspan="5">{{ $category['label'] }}（{{ count($category['rows']) }}件）</th></tr>
                        @foreach ($category['rows'] as $row)
                            <tr>
                                <td>{{ $category['label'] }}</td>
                                <td>{{ $row['employee_number'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['email'] }}</td>
                                <td>{{ $row['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </section>

        <div class="dashboard-panel" aria-label="デモの次のステップ">
            <p><a href="{{ route('register') }}">Freeプランで自社の台帳を始める</a></p>
            <p><a href="{{ route('help.index') }}">サービスのFAQを見る</a></p>
        </div>
    </section>
</x-layouts.app>
