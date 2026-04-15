<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@rtm.com',
            'role' => 'admin',
            'password' => Hash::make('password'),
            'profile_image' => 'https://ui-avatars.com/api/?name=Admin',
            'is_new_user' => false
        ]);
    }
}
