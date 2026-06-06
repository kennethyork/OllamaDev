<?php

declare(strict_types=1);

namespace Boson\Component\Saucer;

final readonly class WebEvent
{
    /**
     * Called when the DOM Content loaded.
     */
    public const int SAUCER_WEB_EVENT_DOM_READY = 0;

    /**
     * Called when a new URL was loaded.
     */
    public const int SAUCER_WEB_EVENT_NAVIGATED = 1;

    /**
     * Called when the URL is about to change.
     */
    public const int SAUCER_WEB_EVENT_NAVIGATE = 2;

    /**
     * Called when the favicon changes.
     */
    public const int SAUCER_WEB_EVENT_FAVICON = 3;

    /**
     * Called when the document title changes.
     */
    public const int SAUCER_WEB_EVENT_TITLE = 4;

    /**
     * Called when the web-page load progresses.
     */
    public const int SAUCER_WEB_EVENT_LOAD = 5;

    private function __construct() {}
}
