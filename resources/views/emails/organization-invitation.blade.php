<p>{{ $invitation->organization->name }}への参加招待が届いています。</p>
<p>招待ロール: {{ $invitation->role->value }}</p>
<p>有効期限: {{ $invitation->expires_at->format('Y-m-d H:i') }}</p>
<p><a href="{{ $url }}">招待を受け入れる</a></p>
<p>利用規約: <a href="{{ route('legal.terms') }}">{{ route('legal.terms') }}</a></p>
