<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // If using Sanctum for API authentication

class User extends Authenticatable // Or Authenticatable, depending on your Laravel version
{
    use HasApiTokens, HasFactory, Notifiable; // HasApiTokens for API authentication

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    // --- E-commerce specific relationships ---

    /**
     * Get the orders for the user.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the cart for the user.
     */
    public function cart()
    {
        // Assuming a one-to-one relationship between User and Cart
        return $this->hasOne(Cart::class);
    }

    // If you have a many-to-many relationship for reviews, etc.
    // public function reviews()
    // {
    //     return $this->hasMany(Review::class);
    // }
}