<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Platform owner
        User::updateOrCreate(['email' => 'owner@peetyai.com'], [
            'name'        => 'Platform Owner',
            'role'        => UserRole::Owner,
            'password'    => Hash::make('password'),
            'retailer_id' => null,
        ]);

        // Retailer: Fresh Mart
        $freshMart = Retailer::firstOrCreate(['slug' => 'fresh-mart'], [
            'name'               => 'Fresh Mart',
            'email'              => 'admin@freshmart.ae',
            'phone'              => '+971501234567',
            'address'            => 'Al Quoz, Dubai, UAE',
            'delivery_radius_km' => 8,
            'commission_rate'    => 5,
            'active'             => true,
            'settings'           => [
                'whatsapp' => [
                    'phone_number_id'     => '',
                    'access_token'        => '',
                    'verify_token'        => 'freshmart_verify_2026',
                    'business_account_id' => '',
                ],
                'ai' => [
                    'openai_api_key' => '',
                    'model'          => 'gpt-4o-mini',
                    'temperature'    => 0.4,
                ],
                'notifications' => [
                    'email_on_new_order'   => true,
                    'email_on_low_stock'   => true,
                    'low_stock_threshold'  => 10,
                ],
            ],
        ]);

        User::updateOrCreate(['email' => 'admin@freshmart.ae'], [
            'retailer_id' => $freshMart->id,
            'name'        => 'Fresh Mart Admin',
            'role'        => UserRole::Retailer,
            'password'    => Hash::make('password'),
        ]);

        User::updateOrCreate(['email' => 'staff@freshmart.ae'], [
            'retailer_id' => $freshMart->id,
            'name'        => 'Fresh Mart Staff',
            'role'        => UserRole::Staff,
            'password'    => Hash::make('password'),
        ]);

        // Retailer: Daily Basket
        $daily = Retailer::firstOrCreate(['slug' => 'daily-basket'], [
            'name'               => 'Daily Basket',
            'email'              => 'admin@dailybasket.ae',
            'phone'              => '+971509876543',
            'address'            => 'Jumeirah, Dubai, UAE',
            'delivery_radius_km' => 5,
            'commission_rate'    => 5,
            'active'             => true,
            'settings'           => [],
        ]);

        User::updateOrCreate(['email' => 'admin@dailybasket.ae'], [
            'retailer_id' => $daily->id,
            'name'        => 'Daily Basket Admin',
            'role'        => UserRole::Retailer,
            'password'    => Hash::make('password'),
        ]);
    }
}
