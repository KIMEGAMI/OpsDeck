<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleSheetConnection extends Model
{
    protected $fillable = [
        'organization_id', 'connected_by', 'google_account_email', 'access_token', 'refresh_token',
        'token_expires_at', 'scopes_json', 'last_synced_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes_json' => 'array',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
