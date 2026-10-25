<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireQuestion;
use App\Models\SecurityControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuestionnaireDraftService
{
    public function generate(Organization $organization, QuestionnaireQuestion $question): QuestionnaireAnswer
    {
        return DB::transaction(function () use ($organization, $question): QuestionnaireAnswer {
            $lockedQuestion = QuestionnaireQuestion::query()
                ->whereKey($question->getKey())
                ->whereHas('questionnaire', fn ($query) => $query->where('organization_id', $organization->id))
                ->lockForUpdate()
                ->firstOrFail();
            $answer = $lockedQuestion->answers()->latest()->lockForUpdate()->first();
            $answer?->refresh();
            if ($answer?->approved_at !== null) {
                throw ValidationException::withMessages([
                    'question_id' => '承認済み回答は回答案で上書きできません。',
                ]);
            }

            $fact = $this->factFor($organization, $question->question_text);
            $attributes = ['answer_text' => $fact['answer_text'], 'answer_choice' => null, 'answer_status' => QuestionnaireAnswer::DRAFT_STATUS, 'source_type' => $fact['source_type'], 'approved_by' => null, 'approved_at' => null];
            if ($answer === null) {
                return $lockedQuestion->answers()->create($attributes);
            }

            $answer->update($attributes);

            return $answer;
        });
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
            if ($control !== null) {
                return [
                    'answer_text' => 'OpsDeckの登録情報では「'.$control->title.'」が対応済みとして記録されています。実際の運用状況と証跡を確認してください。',
                    'source_type' => 'INFERENCE',
                ];
            }
        }

        return ['answer_text' => '現在のOpsDeck登録情報からは回答できません。必要な証跡を確認してください。', 'source_type' => 'UNKNOWN'];
    }
}
