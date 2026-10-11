<?php

namespace App\Services;

use App\Models\Evidence;
use App\Models\Organization;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Models\SecurityControl;
use Illuminate\Support\Str;

class QuestionnaireDraftService
{
    public function generate(Organization $organization, QuestionnaireQuestion $question): QuestionnaireAnswer
    {
        $answer = $question->answers()->latest()->first();
        abort_if($answer?->approved_at !== null, 422, '承認済み回答はAIで上書きできません。');
        $fact = $this->factFor($organization, $question->question_text);
        $attributes = ['answer_text' => $fact['answer_text'], 'answer_choice' => null, 'answer_status' => 'draft', 'source_type' => $fact['source_type'], 'approved_by' => null, 'approved_at' => null];
        if ($answer === null) {
            return $question->answers()->create($attributes);
        }
        $answer->update($attributes);

        return $answer;
    }

    /** @return array{answer_text: string, source_type: string} */
    private function factFor(Organization $organization, string $questionText): array
    {
        $keywordGroups = ['MFA' => ['mfa', '多要素', '二要素'], 'バックアップ' => ['バックアップ', 'backup'], '退職者' => ['退職', 'アカウント停止']];
        foreach ($keywordGroups as $title => $terms) {
            if (! Str::contains(Str::lower($questionText), array_map(static fn (string $term): string => Str::lower($term), $terms))) {
                continue;
            }
            $control = SecurityControl::query()->where('organization_id', $organization->id)->where('status', 'implemented')->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('title', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%');
                }
            })->first();
            $evidence = $control === null ? null : Evidence::query()->where('organization_id', $organization->id)->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('title', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%');
                }
            })->first();
            if ($control !== null && $evidence !== null) {
                return ['answer_text' => 'OpsDeck登録情報では'.$title.'の管理策が実装済みです。証跡「'.$evidence->title.'」を確認してください。', 'source_type' => 'FACT'];
            }
        }

        return ['answer_text' => '現在のOpsDeck登録情報からは回答できません。必要な証跡を確認してください。', 'source_type' => 'UNKNOWN'];
    }
}
