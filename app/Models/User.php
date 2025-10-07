<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user')->withTimestamps();
    }

    public function blogs(): HasMany
    {
        return $this->hasMany(Blog::class, 'creator_id');
    }

    public function news(): HasMany
    {
        return $this->hasMany(News::class, 'creator_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'creator_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function ticketChats(): HasMany
    {
        return $this->hasMany(TicketChat::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class, 'creator_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function moderatedComments(): HasMany
    {
        return $this->hasMany(Comment::class, 'admin_id');
    }

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class, 'creator_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'creator_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'creator_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class, 'creator_id');
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->permissions()->where('slug', $slug)->exists()) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', static function ($query) use ($slug) {
                $query->where('slug', $slug);
            })
            ->exists();
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }
}
