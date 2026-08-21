<?php

declare(strict_types=1);

use Hygo\ApiWaypoint\Compiler\Support\UnmappedReason;
use Hygo\ApiWaypoint\Http\Middleware\VerifyWaypointSecret;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;

/**
 * Laravel Boost discovers a third-party package's guidelines and skills by
 * globbing exactly these two directories, and silently ignores anything it cannot
 * parse. Every failure mode here is quiet: a missing frontmatter key drops the
 * skill, a Blade error empties the guideline. Only a test says so out loud.
 */
function boostPath(string $relative): string
{
    return dirname(__DIR__, 2).'/resources/boost/'.$relative;
}

/**
 * Stands in for Boost's GuidelineAssist. Naming the methods the templates are
 * allowed to call means adding an unsupported one fails here rather than
 * rendering to an empty string inside somebody's install.
 */
function assistStub(): object
{
    return new class
    {
        public function artisanCommand(string $command): string
        {
            return 'php artisan '.$command;
        }

        public function hasSkillsEnabled(): bool
        {
            return true;
        }

        public function hasMcpEnabled(): bool
        {
            return true;
        }
    };
}

function renderBoostTemplate(string $relative): string
{
    // Boost shields backticks and PHP open tags from Blade before rendering; the
    // same substitution here keeps fenced examples from being compiled.
    $contents = str_replace(
        ['`', '<?php'],
        ['___BACKTICK___', '___OPEN_PHP___'],
        (string) file_get_contents(boostPath($relative))
    );

    return Blade::render($contents, ['assist' => assistStub()]);
}

it('ships a guideline where boost looks for one', function (): void {
    expect(glob(boostPath('guidelines/*.blade.php')) ?: [])->not->toBeEmpty();
});

it('ships the skill where boost looks for it', function (): void {
    expect(boostPath('skills/api-waypoint/SKILL.blade.php'))->toBeReadableFile();
});

it('gives the skill the frontmatter boost requires', function (): void {
    $contents = (string) file_get_contents(boostPath('skills/api-waypoint/SKILL.blade.php'));

    expect($contents)->toStartWith('---');

    preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $contents, $matches);

    // Boost drops a skill whose frontmatter lacks either key, without complaining.
    expect($matches[1] ?? '')->toContain('name: api-waypoint')
        ->and($matches[1] ?? '')->toMatch('/description: .+/');
});

it('opens the guideline with the heading boost turns into its description', function (): void {
    expect(renderBoostTemplate('guidelines/core.blade.php'))->toStartWith('# API Waypoint');
});

it('renders both templates as blade without failing', function (): void {
    // A Blade error is rescued and recorded by Boost, which then writes an empty
    // guideline. Compiling here is the only place that surfaces it.
    $guideline = renderBoostTemplate('guidelines/core.blade.php');
    $skill = renderBoostTemplate('skills/api-waypoint/SKILL.blade.php');

    expect($guideline)->not->toBeEmpty()
        ->and($skill)->not->toBeEmpty()
        // Nothing left uninterpolated.
        ->and($guideline)->not->toContain('{{')
        ->and($skill)->not->toContain('{{')
        // The assist stub was actually reached.
        ->and($guideline)->toContain('php artisan waypoint:check');
});

it('names only artisan commands that exist', function (): void {
    $rendered = renderBoostTemplate('guidelines/core.blade.php')
        .renderBoostTemplate('skills/api-waypoint/SKILL.blade.php');

    preg_match_all('/waypoint:[a-z-]+/', $rendered, $matches);

    $documented = array_unique($matches[0]);
    $registered = array_keys(Artisan::all());

    expect($documented)->not->toBeEmpty();

    foreach ($documented as $command) {
        expect($registered)->toContain($command);
    }
});

it('documents every unmapped reason the compiler can emit', function (): void {
    $skill = renderBoostTemplate('skills/api-waypoint/SKILL.blade.php');

    // A reason the skill does not cover is a gap an agent cannot act on.
    foreach (UnmappedReason::all() as $reason) {
        expect($skill)->toContain($reason);
    }
});

it('names the secret header the middleware actually reads', function (): void {
    expect(renderBoostTemplate('skills/api-waypoint/SKILL.blade.php'))
        ->toContain(VerifyWaypointSecret::HEADER);
});

it('states the rule that keeps the package out of production', function (): void {
    $guideline = renderBoostTemplate('guidelines/core.blade.php');

    expect($guideline)->toContain('API_WAYPOINT_ENABLED')
        ->and($guideline)->toContain('production');
});
