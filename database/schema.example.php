<?php

/**
 * hew schema example — three related tables demonstrating all column types and relations.
 *
 * This file is your single source of truth for the database structure.
 * Run `php artisan hew:diff` to see what migrations would be generated.
 * Run `php artisan hew:sync` to generate them.
 *
 * Rules:
 * - hew never emits DROP, RENAME, or any destructive SQL.
 * - All changes must be additive. Columns removed here show up as warnings, not migrations.
 * - Relation methods (hasMany, belongsTo, etc.) are metadata — they don't create columns.
 */

use Boquizo\Hew\Schema\Column;
use Boquizo\Hew\Schema\Schema;
use Boquizo\Hew\Schema\Table;

return Schema::define([

    // --- users -----------------------------------------------------------
    // Standard user table. Demonstrates most column types and modifiers.
    Table::make('users')
        ->columns([
            Column::id(),                                          // BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
            Column::string('name'),
            Column::string('email')->unique(),
            Column::string('password')->hidden(),                  // ->hidden() is metadata for $hidden in the model
            Column::string('avatar')->nullable(),
            Column::boolean('is_admin')->default(false),
            Column::json('preferences')->nullable(),
            Column::timestamp('email_verified_at')->nullable(),
            Column::timestamps(),                                  // created_at + updated_at shortcut
        ])
        ->hasMany('posts')
        ->hasOne('profile')
        ->belongsToMany('roles'),                                  // generates pivot table: role_user

    // --- posts -----------------------------------------------------------
    // Belongs to a user; each post has many comments.
    // Demonstrates foreignId with ->references().
    Table::make('posts')
        ->columns([
            Column::id(),
            Column::foreignId('user_id')->references('users'),     // generates ->constrained('users')
            Column::string('title'),
            Column::string('slug')->unique()->index(),
            Column::text('body'),
            Column::decimal('reading_fee', 10, 2)->nullable(),     // decimal requires precision + scale
            Column::boolean('is_published')->default(false),
            Column::string('status')->cast('App\\Enums\\PostStatus'), // ->cast() is metadata for $casts in the model
            Column::timestamp('published_at')->nullable(),
            Column::timestamps(),
        ])
        ->belongsTo('users')
        ->hasMany('comments'),

    // --- roles -----------------------------------------------------------
    // Simple lookup table. belongsToMany on users generates role_user pivot automatically.
    Table::make('roles')
        ->columns([
            Column::id(),
            Column::string('name')->unique(),
            Column::string('description')->nullable(),
            Column::timestamps(),
        ])
        ->belongsToMany('users'),

]);
