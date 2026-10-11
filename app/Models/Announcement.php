<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'created_by', 'published_at', 'recipients_count'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'announcement_organizations');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AnnouncementDelivery::class);
    }
}
