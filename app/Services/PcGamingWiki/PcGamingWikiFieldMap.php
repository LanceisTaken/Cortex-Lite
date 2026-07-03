<?php

namespace App\Services\PcGamingWiki;

class PcGamingWikiFieldMap
{
    public const INFOBOX_TABLE = 'Infobox_game';

    public const VIDEO_TABLE = 'Video';

    public const STEAM_APP_ID = 'Steam_AppID';

    public const PAGE_ALIAS = 'Page';

    public const LOCAL_TO_CARGO = [
        'dlss_supported' => 'Upscaling',
        'fsr_supported' => 'Upscaling',
        'hdr_supported' => 'HDR',
        'ultrawide_supported' => 'Ultrawidescreen',
        'ray_tracing_supported' => 'Ray_tracing',
    ];

    public const INFOBOX_FIELDS = [
        '_pageName='.self::PAGE_ALIAS,
        self::STEAM_APP_ID,
    ];

    public const VIDEO_FIELDS = [
        '_pageName='.self::PAGE_ALIAS,
        'HDR',
        'Upscaling',
        'Ultrawidescreen',
        'Ray_tracing',
    ];
}
