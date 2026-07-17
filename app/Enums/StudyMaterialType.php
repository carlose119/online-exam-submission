<?php

namespace App\Enums;

enum StudyMaterialType: string
{
    case File = 'FILE';
    case Link = 'LINK';
    case Meeting = 'MEETING';

    public function getLabel(): string
    {
        return match ($this) {
            self::File => 'File',
            self::Link => 'Link',
            self::Meeting => 'Meeting',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::File => 'blue',
            self::Link => 'green',
            self::Meeting => 'amber',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::File => 'heroicon-o-document-text',
            self::Link => 'heroicon-o-link',
            self::Meeting => 'heroicon-o-video-camera',
        };
    }
}
