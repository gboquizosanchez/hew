<?php

declare(strict_types=1);

namespace Boquizo\Hew\Schema;

use Illuminate\Support\Str;

class ColumnDef
{
    public readonly string $name;

    public string $type;

    /** @var array<int, int|string> */
    public readonly array $parameters;

    public bool $isNullable = false;

    public string|int|float|bool|null $defaultValue = null;

    public bool $hasDefault = false;

    public bool $isPrimary = false;

    public ?string $size = null;

    public bool $isUnique = false;

    public bool $isUnsigned = false;

    public bool $hasIndex = false;

    public bool $isHidden = false;

    public ?string $castClass = null;

    public ?string $referencesTable = null;

    public bool $useCurrent = false;

    public ?string $onDelete = null;

    /** @var ColumnDef[] */
    private array $children = [];

    private bool $isShortcut = false;

    /** @param array<int, int|string> $parameters */
    public function __construct(string $name, string $type, array $parameters = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->parameters = $parameters;
    }

    /** @internal */
    public static function makeShortcut(string $name, string $type, array $children): self
    {
        $col = new self($name, $type);
        $col->isShortcut = true;
        $col->children = $children;

        return $col;
    }

    public function morphs(): self
    {
        $this->type = match ($this->type) {
            'uuid' => 'uuidMorphs',
            'ulid' => 'ulidMorphs',
            default => 'morphs',
        };

        return $this;
    }

    public function uuid(): self
    {
        $this->type = match ($this->type) {
            'morphs' => 'uuidMorphs',
            'nullableMorphs' => 'nullableUuidMorphs',
            default => $this->type,
        };

        return $this;
    }

    public function foreign(): self
    {
        $this->type = match ($this->type) {
            'id' => 'foreignId',
            'uuid' => 'foreignUuid',
            'ulid' => 'foreignUlid',
            default => $this->type,
        };

        return $this;
    }

    public function nullable(): self
    {
        $this->isNullable = true;

        return $this;
    }

    public function default(string|int|float|bool|null $value): self
    {
        $this->defaultValue = $value;
        $this->hasDefault = true;

        return $this;
    }

    public function primary(): self
    {
        $this->isPrimary = true;

        return $this;
    }

    public function big(): self
    {
        $this->size = 'big';

        return $this;
    }

    public function tiny(): self
    {
        $this->size = 'tiny';

        return $this;
    }

    public function small(): self
    {
        $this->size = 'small';

        return $this;
    }

    public function medium(): self
    {
        $this->size = 'medium';

        return $this;
    }

    public function long(): self
    {
        $this->size = 'long';

        return $this;
    }

    public function unique(): self
    {
        $this->isUnique = true;

        return $this;
    }

    public function unsigned(): self
    {
        $this->isUnsigned = true;

        return $this;
    }

    public function index(): self
    {
        $this->hasIndex = true;

        return $this;
    }

    public function useCurrent(): self
    {
        $this->useCurrent = true;

        return $this;
    }

    public function cascadeOnDelete(): self
    {
        $this->onDelete = 'cascade';

        return $this;
    }

    public function nullOnDelete(): self
    {
        $this->onDelete = 'null';

        return $this;
    }

    public function restrictOnDelete(): self
    {
        $this->onDelete = 'restrict';

        return $this;
    }

    public function hidden(): self
    {
        $this->isHidden = true;

        return $this;
    }

    public function cast(string $class): self
    {
        $this->castClass = $class;

        return $this;
    }

    public function references(string $table = ''): self
    {
        $this->referencesTable = $table !== '' ? $table : Str::plural(
            str_ends_with($this->name, '_id') ? substr($this->name, 0, -3) : $this->name
        );

        return $this;
    }

    public function isShortcut(): bool
    {
        return $this->isShortcut;
    }

    /** @return ColumnDef[] */
    public function children(): array
    {
        return $this->children;
    }
}
