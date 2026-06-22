<?php

use Boquizo\Hew\Parser\MigrationParser;

$fixtures = dirname(__DIR__).'/fixtures/migrations';

function tmpMigrationDir(): string
{
    $dir = sys_get_temp_dir().'/hew_test_'.uniqid();
    mkdir($dir);

    return $dir;
}

function cleanDir(string $dir): void
{
    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);
}

it('parses a simple create migration', function () use ($fixtures): void {
    $dir = tmpMigrationDir();
    copy($fixtures.'/2024_01_01_000000_create_users_table.php', $dir.'/2024_01_01_000000_create_users_table.php');

    $tables = (new MigrationParser)->parse($dir);

    expect($tables)->toHaveKey('users')
        ->and($tables['users']->columns)->toHaveKey('id')
        ->and($tables['users']->columns)->toHaveKey('name')
        ->and($tables['users']->columns)->toHaveKey('email')
        ->and($tables['users']->columns)->toHaveKey('created_at')
        ->and($tables['users']->columns)->toHaveKey('updated_at');

    cleanDir($dir);
});

it('accumulates columns from a Schema::table migration', function () use ($fixtures): void {
    $dir = tmpMigrationDir();
    copy($fixtures.'/2024_01_01_000000_create_users_table.php', $dir.'/2024_01_01_000000_create_users_table.php');
    copy($fixtures.'/2024_01_02_000000_add_avatar_to_users_table.php', $dir.'/2024_01_02_000000_add_avatar_to_users_table.php');

    $tables = (new MigrationParser)->parse($dir);

    expect($tables)->toHaveKey('users')
        ->and($tables['users']->columns)->toHaveKey('avatar')
        ->and($tables['users']->columns)->toHaveKey('name');

    cleanDir($dir);
});

it('processes multiple migrations in filename order', function () use ($fixtures): void {
    $dir = tmpMigrationDir();
    copy($fixtures.'/2024_01_01_000000_create_users_table.php', $dir.'/2024_01_01_000000_create_users_table.php');
    copy($fixtures.'/2024_01_04_000000_create_posts_table_with_destructive.php', $dir.'/2024_01_04_000000_create_posts_table_with_destructive.php');

    $tables = (new MigrationParser)->parse($dir);

    expect($tables)->toHaveKey('users')
        ->and($tables)->toHaveKey('posts')
        ->and($tables['posts']->columns)->toHaveKey('title')
        ->and($tables['posts']->columns)->toHaveKey('body');

    cleanDir($dir);
});

it('ignores malformed files without crashing', function () use ($fixtures): void {
    $dir = tmpMigrationDir();
    copy($fixtures.'/2024_01_03_000000_malformed_migration.php', $dir.'/2024_01_03_000000_malformed_migration.php');

    $parser = new MigrationParser;
    $tables = $parser->parse($dir);

    expect($tables)->toBe([])
        ->and($parser->getUnparseable())->toContain('2024_01_03_000000_malformed_migration.php');

    cleanDir($dir);
});

it('applies dropColumn from alter migrations', function () use ($fixtures): void {
    $dir = tmpMigrationDir();
    copy($fixtures.'/2024_01_05_000000_destructive_in_up.php', $dir.'/2024_01_05_000000_destructive_in_up.php');

    $parser = new MigrationParser;
    $tables = $parser->parse($dir);

    expect($tables['users']->columns ?? [])->not->toHaveKey('old_field');

    cleanDir($dir);
});

it('returns empty array for an empty directory', function (): void {
    $dir = sys_get_temp_dir().'/hew_test_empty_'.uniqid();
    mkdir($dir);

    expect((new MigrationParser)->parse($dir))->toBe([]);

    rmdir($dir);
});

function migrationWith(string $body): string
{
    return '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'test\', static function (Blueprint $table): void {
'.$body.'
        });
    }
    public function down(): void { Schema::dropIfExists(\'test\'); }
};';
}

it('captures nullable modifier', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith('$table->string(\'token\')->nullable();'));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['token']->modifiers['nullable'] ?? false)->toBeTrue();
    cleanDir($dir);
});

it('captures unique modifier', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith('$table->string(\'email\')->unique();'));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['email']->modifiers['unique'] ?? false)->toBeTrue();
    cleanDir($dir);
});

it('captures default modifier', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith('$table->boolean(\'active\')->default(false);'));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['active']->modifiers['default'] ?? null)->toBe('false');
    cleanDir($dir);
});

it('captures constrained with explicit table', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith('$table->foreignId(\'user_id\')->constrained(\'users\');'));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['user_id']->modifiers['references'] ?? null)->toBe('users');
    cleanDir($dir);
});

it('infers table from constrained() with no arg', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith('$table->foreignId(\'user_id\')->constrained();'));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['user_id']->modifiers['references'] ?? null)->toBe('users');
    cleanDir($dir);
});

it('captures old-style references/on FK', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith(
        '$table->foreignId(\'post_id\')->references(\'id\')->on(\'posts\');'
    ));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['post_id']->modifiers['references'] ?? null)->toBe('posts');
    cleanDir($dir);
});

it('captures useCurrent modifier', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith('$table->timestamp(\'created_at\')->useCurrent();'));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['created_at']->modifiers['useCurrent'] ?? false)->toBeTrue();
    cleanDir($dir);
});

it('captures cascadeOnDelete modifier', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith(
        '$table->foreignId(\'user_id\')->constrained(\'users\')->cascadeOnDelete();'
    ));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['user_id']->modifiers['onDelete'] ?? null)->toBe('cascade');
    cleanDir($dir);
});

it('maps enum column to string type', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith(
        "\$table->enum('status', ['draft', 'published']);"
    ));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['status']->type ?? null)->toBe('string');
    cleanDir($dir);
});

it('resolves foreignIdFor via heuristic', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', migrationWith(
        '$table->foreignIdFor(\App\Models\UserProfile::class);'
    ));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns)->toHaveKey('user_profile_id');
    expect($tables['test']->columns['user_profile_id']->modifiers['references'] ?? null)->toBe('user_profiles');
    cleanDir($dir);
});

it('applies ->change() column modifications from alter migrations', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000000_create.php', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'users\', function (Blueprint $table) {
            $table->id();
            $table->string(\'name\', 50);
        });
    }
    public function down(): void {}
};');
    file_put_contents($dir.'/2024_01_01_000001_alter.php', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table(\'users\', function (Blueprint $table) {
            $table->string(\'name\', 100)->change();
        });
    }
    public function down(): void {}
};');

    $parser = new MigrationParser;
    $tables = $parser->parse($dir);

    expect($tables['users']->columns['name']->modifiers['length'] ?? null)->toBe(100);
    cleanDir($dir);
});

it('parses ALTER TABLE MODIFY from DB::statement', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_raw.php', '<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void { DB::statement("ALTER TABLE users MODIFY name MEDIUMTEXT NOT NULL"); }
    public function down(): void {}
};');

    $parser = new MigrationParser;
    $tables = $parser->parse($dir);

    expect($tables['users']->columns['name']->type)->toBe('mediumText');
    expect(array_key_exists('nullable', $tables['users']->columns['name']->modifiers))->toBeFalse();
    cleanDir($dir);
});

it('resolves old-style standalone FK declaration (Phase 2)', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000000_t.php', migrationWith('
        $table->id();
        $table->unsignedBigInteger(\'recruiter_id\')->index()->nullable();
        $table->foreign(\'recruiter_id\')->references(\'id\')->on(\'users\')->onDelete(\'cascade\');
    '));

    $tables = (new MigrationParser)->parse($dir);

    $col = $tables['test']->columns['recruiter_id'] ?? null;
    expect($col)->not->toBeNull()
        ->and($col->modifiers['references'])->toBe('users')
        ->and($col->modifiers['onDelete'])->toBe('cascade');
    cleanDir($dir);
});

it('applies renameColumn from alter migrations (Phase 3)', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000000_create.php', migrationWith('$table->id(); $table->string(\'old_name\');'));
    file_put_contents($dir.'/2024_01_01_000001_alter.php', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table(\'test\', function (Blueprint $table) {
            $table->renameColumn(\'old_name\', \'new_name\');
        });
    }
    public function down(): void {}
};');

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns)->not->toHaveKey('old_name')
        ->and($tables['test']->columns)->toHaveKey('new_name');
    cleanDir($dir);
});

it('adds new column via alter migration (Phase 3)', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000000_create.php', migrationWith('$table->id();'));
    file_put_contents($dir.'/2024_01_01_000001_alter.php', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table(\'test\', function (Blueprint $table) {
            $table->string(\'email\')->after(\'id\');
        });
    }
    public function down(): void {}
};');

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns)->toHaveKey('email');
    // insertAfter: email should come right after id
    $keys = array_keys($tables['test']->columns);
    expect($keys[0])->toBe('id')
        ->and($keys[1])->toBe('email');
    cleanDir($dir);
});

it('handles $blueprint variable name (not $table)', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000001_t.php', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create(\'items\', static function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string(\'name\');
        });
    }
    public function down(): void { Schema::dropIfExists(\'items\'); }
};');

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['items']->columns)->toHaveKey('id')
        ->and($tables['items']->columns)->toHaveKey('name');
    cleanDir($dir);
});

it('parses softDeletes as softDeletes type (Phase 4)', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000000_t.php', migrationWith(
        '$table->id(); $table->softDeletes();'
    ));

    $tables = (new MigrationParser)->parse($dir);

    expect($tables['test']->columns['deleted_at']->type)->toBe('softDeletes');
    cleanDir($dir);
});

it('removes table on Schema::dropIfExists in up() (Phase 4)', function (): void {
    $dir = tmpMigrationDir();
    file_put_contents($dir.'/2024_01_01_000000_create.php', migrationWith('$table->id();'));
    file_put_contents($dir.'/2024_01_01_000001_drop.php', '<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::dropIfExists(\'test\'); }
    public function down(): void {}
};');

    $parser = new MigrationParser;
    $tables = $parser->parse($dir);

    expect($tables)->not->toHaveKey('test')
        ->and($parser->getDestructiveOps())->not->toBeEmpty();
    cleanDir($dir);
});
