<?php

declare(strict_types=1);

namespace Boquizo\Hew\Schema;

use Boquizo\Hew\Exceptions\UnsupportedColumnTypeException;

class Column
{
    public static function id(string $name = 'id'): ColumnDef
    {
        return new ColumnDef($name, 'id');
    }

    public static function string(string $name, int $length = 0): ColumnDef
    {
        return new ColumnDef($name, 'string', $length > 0 ? [$length] : []);
    }

    public static function text(string $name): ColumnDef
    {
        return new ColumnDef($name, 'text');
    }

    public static function integer(string $name): ColumnDef
    {
        return new ColumnDef($name, 'integer');
    }

    public static function bigInteger(string $name): ColumnDef
    {
        $col = new ColumnDef($name, 'integer');
        $col->size = 'big';

        return $col;
    }

    public static function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDef
    {
        return new ColumnDef($name, 'decimal', [$precision, $scale]);
    }

    public static function float(string $name): ColumnDef
    {
        return new ColumnDef($name, 'float');
    }

    public static function boolean(string $name): ColumnDef
    {
        return new ColumnDef($name, 'boolean');
    }

    public static function json(string $name): ColumnDef
    {
        return new ColumnDef($name, 'json');
    }

    public static function timestamp(string $name): ColumnDef
    {
        return new ColumnDef($name, 'timestamp');
    }

    public static function timestamps(): ColumnDef
    {
        return ColumnDef::makeShortcut('timestamps', 'timestamps', [
            new ColumnDef('created_at', 'timestamp'),
            new ColumnDef('updated_at', 'timestamp'),
        ]);
    }

    public static function softDeletes(): ColumnDef
    {
        return ColumnDef::makeShortcut('deleted_at', 'softDeletes', [
            new ColumnDef('deleted_at', 'timestamp'),
        ]);
    }

    public static function uuid(string $name): ColumnDef
    {
        return new ColumnDef($name, 'uuid');
    }

    public static function ulid(string $name): ColumnDef
    {
        return new ColumnDef($name, 'ulid');
    }

    public static function date(string $name): ColumnDef
    {
        return new ColumnDef($name, 'date');
    }

    public static function time(string $name): ColumnDef
    {
        return new ColumnDef($name, 'time');
    }

    public static function binary(string $name): ColumnDef
    {
        return new ColumnDef($name, 'binary');
    }

    public static function ipAddress(string $name): ColumnDef
    {
        return new ColumnDef($name, 'ipAddress');
    }

    public static function macAddress(string $name): ColumnDef
    {
        return new ColumnDef($name, 'macAddress');
    }

    public static function year(string $name): ColumnDef
    {
        return new ColumnDef($name, 'year');
    }

    public static function rememberToken(): ColumnDef
    {
        return new ColumnDef('remember_token', 'rememberToken');
    }

    public static function morphs(string $name): ColumnDef
    {
        return new ColumnDef($name, 'morphs');
    }

    /** @throws UnsupportedColumnTypeException */
    public static function enum(string $name): never
    {
        throw new UnsupportedColumnTypeException(
            'Enum columns are not supported. Use Column::string() with ->cast(YourEnum::class) instead.',
        );
    }
}
