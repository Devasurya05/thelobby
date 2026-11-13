<?php

namespace Tests\Feature;

use App\Support\Assets;
use Tests\TestCase;

/** Our CSS and JavaScript links change when the files change, so a release is never hidden by a browser's old copy. */
class AssetsTest extends TestCase
{
    public function test_a_link_carries_the_files_last_changed_time(): void
    {
        $url = Assets::url('css/app.css');

        $this->assertMatchesRegularExpression('#/css/app\.css\?v=\d+$#', $url);
        $this->assertStringEndsWith('?v='.filemtime(public_path('css/app.css')), $url);
    }

    public function test_a_missing_file_still_gives_a_plain_link_instead_of_an_error(): void
    {
        $this->assertStringEndsWith('/css/does-not-exist.css', Assets::url('css/does-not-exist.css'));
    }

    public function test_pages_link_the_versioned_stylesheet_and_scripts(): void
    {
        $page = $this->get('/')->assertOk();

        $page->assertSee('css/app.css?v=', false)->assertSee('js/menu.js?v=', false)->assertSee('js/home.js?v=', false);
        $this->get('/demo')->assertOk()->assertSee('css/app.css?v=', false)->assertSee('js/confirm.js?v=', false);
    }
}
