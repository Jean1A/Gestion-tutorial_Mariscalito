<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Notifications\QueuedEmailVerification;
use App\Notifications\QueuedPasswordReset;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'dni', 'email', 'phone', 'password', 'is_active', 'must_change_password', 'session_version', 'office_hours'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->whereIn('roles.name', array_column(RoleName::cases(), 'value'));
    }

    public function tutoredSections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'tutor_assignments')->withPivot('is_active')->withTimestamps();
    }

    public function getWhatsappUrlAttribute(): ?string
    {
        $digits = preg_replace('/\D+/', '', $this->phone ?? '');
        if (strlen($digits) === 9) {
            $digits = '51'.$digits;
        } elseif (! str_starts_with($this->phone ?? '', '+') && ! (strlen($digits) === 11 && str_starts_with($digits, '51'))) {
            return null;
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? 'https://wa.me/'.$digits : null;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedPasswordReset($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        if ($this->email) {
            $this->notify(new QueuedEmailVerification);
        }
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student')->withPivot(['relationship', 'is_active', 'verified_by', 'verified_at'])->withTimestamps();
    }

    public function hasRole(RoleName $role): bool
    {
        return $this->is_active && ! $this->trashed() && $this->roles->contains('name', $role);
    }

    public function defaultRole(): ?RoleName
    {
        foreach ([RoleName::Coordinator, RoleName::Administrator, RoleName::Tutor, RoleName::Teacher, RoleName::Assistant, RoleName::Guardian, RoleName::Student] as $role) {
            if ($this->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    public function activeRole(): ?RoleName
    {
        $selected = request()->hasSession() ? request()->session()->get('active_role') : null;
        $role = is_string($selected) ? RoleName::tryFrom($selected) : null;

        return $role && $this->hasRole($role) ? $role : $this->defaultRole();
    }

    public function permits(string $permission): bool
    {
        $role = $this->activeRole();

        return $role && $this->is_active && ! $this->trashed()
            && $this->roles()->where('name', $role->value)->whereHas('permissions', fn ($query) => $query->where('name', $permission))->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'external_notifications_enabled' => 'boolean',
            'session_version' => 'integer',
        ];
    }
}
