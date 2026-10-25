@props(['currentStep' => 1, 'completed' => false])

<nav class="my-4" aria-label="Excelなどの取込手順">
    <ol class="grid grid-cols-1 gap-2 sm:grid-cols-3">
        @foreach ([1 => '表を選ぶ', 2 => '列の意味を合わせる', 3 => '内容を確認して反映'] as $stepNumber => $stepLabel)
            @php($stepIsCurrent = ! $completed && $stepNumber === $currentStep)
            @php($stepIsComplete = $completed || $stepNumber < $currentStep)
            <li @class([
                'rounded border px-3 py-2 text-center font-semibold',
                'border-green-800 bg-green-800 text-white' => $stepIsComplete,
                'border-blue-900 bg-blue-900 text-white' => $stepIsCurrent,
                'border-gray-500 bg-gray-100 text-gray-900' => ! $stepIsComplete && ! $stepIsCurrent,
            ]) @if ($stepIsCurrent) aria-current="step" @endif>
                <span>{{ $stepNumber }}. {{ $stepLabel }}</span>
                @if ($stepIsCurrent)
                    <span class="sr-only">（現在の手順）</span>
                @elseif ($stepIsComplete)
                    <span class="sr-only">（完了）</span>
                @else
                    <span class="sr-only">（これから）</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
