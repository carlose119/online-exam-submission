<?php

namespace App\Enums;

enum QuestionType: string
{
    case Single = 'SINGLE';
    case Multiple = 'MULTIPLE';

    public function getLabel(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Multiple => 'Multiple',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Single => 'info',
            self::Multiple => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Single => 'heroicon-o-check-circle',
            self::Multiple => 'heroicon-o-check',
        };
    }
}
