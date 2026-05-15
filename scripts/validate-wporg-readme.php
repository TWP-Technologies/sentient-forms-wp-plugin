<?php
/**
 * Validate Sentient Forms readme.txt for WordPress.org readiness.
 *
 * By default this runs local deterministic checks and then posts the readme to
 * the official WordPress.org Readme Validator. Use --offline for local checks
 * only when the official validator is unreachable.
 */

if ( PHP_SAPI !== 'cli' )
{
    fwrite( STDERR, "This script must be run from the command line.\n" );
    exit( 1 );
}

$offline = false;
$root_arg = null;

foreach ( array_slice( $argv, 1 ) as $arg )
{
    if ( '--offline' === $arg )
    {
        $offline = true;
        continue;
    }

    if ( null === $root_arg )
    {
        $root_arg = $arg;
        continue;
    }

    fwrite( STDERR, "Usage: php scripts/validate-wporg-readme.php [--offline] [plugin-directory-or-readme]\n" );
    exit( 1 );
}

$target = realpath( $root_arg ?? dirname( __DIR__ ) );
if ( false === $target )
{
    fwrite( STDERR, "Usage: php scripts/validate-wporg-readme.php [--offline] [plugin-directory-or-readme]\n" );
    exit( 1 );
}

$readme_path = is_dir( $target ) ? $target . '/readme.txt' : $target;
$plugin_root = is_dir( $target ) ? $target : dirname( $target );
$plugin_file = $plugin_root . '/sentient-forms.php';

$issues = validate_readme_locally( $readme_path, $plugin_file );

if ( ! $offline )
{
    $issues = array_merge( $issues, validate_readme_with_official_service( $readme_path ) );
}

if ( [] !== $issues )
{
    echo "Sentient Forms WordPress.org readme validation failed:\n";
    foreach ( $issues as $issue )
    {
        echo "- {$issue}\n";
    }
    exit( 1 );
}

$mode = $offline ? 'local' : 'local and official';
echo "Sentient Forms WordPress.org readme {$mode} validation passed for {$readme_path}.\n";

/**
 * Validate readme fields that should not depend on a remote service.
 *
 * @return array<int,string>
 */
function validate_readme_locally( string $readme_path, string $plugin_file ): array
{
    if ( ! file_exists( $readme_path ) )
    {
        return [ 'Missing readme.txt.' ];
    }

    $readme = file_get_contents( $readme_path );
    if ( false === $readme )
    {
        return [ 'Could not read readme.txt.' ];
    }

    $issues = [];

    if ( strlen( $readme ) > 10000 )
    {
        $issues[] = 'readme.txt should stay under 10 KB to avoid WordPress.org parsing problems.';
    }

    if ( ! preg_match( '/^===\s*Sentient Forms\s*===\s*$/m', $readme ) )
    {
        $issues[] = 'readme.txt must start with the Sentient Forms title header.';
    }

    foreach ( [ 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ] as $field )
    {
        if ( null === parse_readme_header_value( $readme, $field ) )
        {
            $issues[] = "readme.txt is missing {$field}.";
        }
    }

    $tags = parse_readme_header_value( $readme, 'Tags' );
    if ( null !== $tags && count( array_filter( array_map( 'trim', explode( ',', $tags ) ) ) ) > 5 )
    {
        $issues[] = 'readme.txt Tags must contain no more than five terms.';
    }

    $stable_tag = parse_readme_header_value( $readme, 'Stable tag' );
    $version    = file_exists( $plugin_file ) ? parse_plugin_version( $plugin_file ) : null;
    if ( null !== $stable_tag && 'trunk' === strtolower( $stable_tag ) )
    {
        $issues[] = 'readme.txt Stable tag must not be trunk for a new WordPress.org submission.';
    }
    elseif ( null !== $stable_tag && null !== $version && $stable_tag !== $version )
    {
        $issues[] = "readme.txt Stable tag ({$stable_tag}) must match plugin Version ({$version}).";
    }

    $short_description = parse_short_description( $readme );
    if ( '' === $short_description )
    {
        $issues[] = 'readme.txt must include a short description after the header block.';
    }
    elseif ( strlen( $short_description ) > 150 )
    {
        $issues[] = 'readme.txt short description must be 150 characters or fewer.';
    }

    foreach ( [ 'Description', 'Installation', 'Frequently Asked Questions', 'Changelog' ] as $section )
    {
        if ( ! preg_match( '/^==\s*' . preg_quote( $section, '/' ) . '\s*==\s*$/mi', $readme ) )
        {
            $issues[] = "readme.txt is missing the {$section} section.";
        }
    }

    foreach ( [ 'OpenRouter', 'Sentient managed', 'Data sent', 'Terms', 'Privacy policy' ] as $required_disclosure )
    {
        if ( false === stripos( $readme, $required_disclosure ) )
        {
            $issues[] = "readme.txt external-service disclosure is missing '{$required_disclosure}'.";
        }
    }

    foreach ( [ 'sentient-forms-release-source', 'bun run build:wp' ] as $required_source_reference )
    {
        if ( false === stripos( $readme, $required_source_reference ) )
        {
            $issues[] = "readme.txt compressed-source disclosure is missing '{$required_source_reference}'.";
        }
    }

    return $issues;
}

/**
 * Validate against the official WordPress.org Readme Validator.
 *
 * @return array<int,string>
 */
function validate_readme_with_official_service( string $readme_path ): array
{
    $readme = file_get_contents( $readme_path );
    if ( false === $readme )
    {
        return [ 'Could not read readme.txt for official validation.' ];
    }

    $payload = http_build_query(
        [
            'readme'          => '',
            'readme_contents' => base64_encode( $readme ),
        ]
    );

    $context = stream_context_create(
        [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SentientFormsReadmePreflight/0.1\r\n",
                'content' => $payload,
                'timeout' => 20,
            ],
        ]
    );

    $html = @file_get_contents( 'https://wordpress.org/plugins/developers/readme-validator/', false, $context );
    if ( false === $html )
    {
        return [ 'Official WordPress.org readme validator could not be reached; rerun or use --offline for local-only checks.' ];
    }

    $errors   = extract_official_validator_list( $html, 'errors' );
    $warnings = extract_official_validator_list( $html, 'warnings' );

    if ( null === $errors || null === $warnings )
    {
        return [ 'Official WordPress.org readme validator response shape was not recognized.' ];
    }

    $issues = [];
    foreach ( $errors as $error )
    {
        $issues[] = "Official readme validator error: {$error}";
    }

    foreach ( $warnings as $warning )
    {
        $issues[] = "Official readme validator warning: {$warning}";
    }

    return $issues;
}

/**
 * Extract an errors or warnings list from the official validator response.
 *
 * @return array<int,string>|null
 */
function extract_official_validator_list( string $html, string $class ): ?array
{
    if ( ! preg_match( "/<ul class=['\"]{$class}['\"]>(.*?)<\/ul>/is", $html, $matches ) )
    {
        return str_contains( $html, '<ul class=\'notes\'>' ) ? [] : null;
    }

    preg_match_all( '/<li>(.*?)<\/li>/is', $matches[1], $items );

    return array_values(
        array_filter(
            array_map(
                static fn ( string $item ): string => trim( html_entity_decode( wporg_readme_plain_text( $item ), ENT_QUOTES | ENT_HTML5 ) ),
                $items[1] ?? []
            )
        )
    );
}

/**
 * Convert small HTML fragments to comparable plain text.
 */
function wporg_readme_plain_text( string $html ): string
{
    return preg_replace( '/\s+/', ' ', strip_tags( $html ) ) ?? '';
}

/**
 * Parse a WordPress.org readme header value.
 */
function parse_readme_header_value( string $readme, string $field ): ?string
{
    if ( ! preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi', $readme, $matches ) )
    {
        return null;
    }

    return trim( $matches[1] );
}

/**
 * Parse the short description that follows the readme header block.
 */
function parse_short_description( string $readme ): string
{
    $lines      = preg_split( '/\R/', $readme ) ?: [];
    $in_headers = false;

    foreach ( $lines as $line )
    {
        $trimmed = trim( $line );

        if ( preg_match( '/^===.+===$/', $trimmed ) )
        {
            $in_headers = true;
            continue;
        }

        if ( $in_headers && '' === $trimmed )
        {
            $in_headers = false;
            continue;
        }

        if ( ! $in_headers && '' !== $trimmed )
        {
            return $trimmed;
        }
    }

    return '';
}

/**
 * Parse the main plugin Version header.
 */
function parse_plugin_version( string $plugin_file ): ?string
{
    $contents = file_get_contents( $plugin_file );
    if ( false === $contents || ! preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $matches ) )
    {
        return null;
    }

    return trim( $matches[1] );
}
