<?php
/**
 * Build Script: Generate Class Map for Sentient Forms.
 * This script scans the plugin's directories (excluding 'vendor')
 * and generates a class_map.php file for optimized autoloading.
 * The paths in the generated map will be relative to the map file's directory.
 * How to run:
 * 1. Place this script in a 'build' directory in your plugin's root.
 * (e.g., wp-content/plugins/your-plugin-name/build/generate-classmap.php)
 * 2. Ensure SENTIENT_FORMS_PLUGIN_DIR_FOR_BUILD is correctly defined below.
 * 3. Run from the command line: php build/generate-classmap.php
 *
 * @package SentientFormsBuild
 */

echo "Starting Sentient Forms class map generation...\n";
$check_only = in_array( '--check', $argv, true );

// Define the plugin directory. Adjust if this script is placed elsewhere.
if ( !defined( 'SENTIENT_FORMS_PLUGIN_DIR_FOR_BUILD' ) )
{
    // Assumes this script is in 'wp-plugin-root/build/'
    define( 'SENTIENT_FORMS_PLUGIN_DIR_FOR_BUILD', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}

$plugin_dir = realpath( SENTIENT_FORMS_PLUGIN_DIR_FOR_BUILD ) . DIRECTORY_SEPARATOR;

// Directories to scan within the plugin.
// Paths should be relative to $plugin_dir.
$scan_directories = [
    'includes' . DIRECTORY_SEPARATOR,
    // Add other directories like 'admin/', 'llms/' if they are at the same level as 'includes/'
    // and contain classes, AND if class-map.php is intended to be in the plugin root.
    // However, typically class-map.php is in 'includes/', and it maps files within 'includes/'
    // or uses '../' for files outside 'includes/'.
    // For simplicity, this script assumes class-map.php is in 'includes/' and primarily maps files from there.
];

// Directories to explicitly exclude from scanning (relative to $plugin_dir).
$exclude_directories = [
    'vendor' . DIRECTORY_SEPARATOR,
    'build' . DIRECTORY_SEPARATOR,
    'assets' . DIRECTORY_SEPARATOR,
    'languages' . DIRECTORY_SEPARATOR,
    'node_modules' . DIRECTORY_SEPARATOR,
];

// Output file for the class map.
// This script assumes class-map.php will be in the 'includes' directory.
$output_file          = $plugin_dir . 'includes' . DIRECTORY_SEPARATOR . 'class-map.php';
$class_map_output_dir = dirname( $output_file ); // Absolute path to 'includes' directory

$class_map = [];

echo "Plugin directory for build: " . $plugin_dir . "\n";
echo "Class map will be generated at: " . $output_file . "\n";
echo "Paths in map will be relative to: " . $class_map_output_dir . "\n";

foreach ( $scan_directories as $scan_dir_relative )
{
    $current_scan_dir_abs = realpath( $plugin_dir . $scan_dir_relative );

    if ( !$current_scan_dir_abs || !is_dir( $current_scan_dir_abs ) )
    {
        echo "Warning: Directory to scan not found or not accessible: " . $plugin_dir . $scan_dir_relative . "\n";
        continue;
    }

    echo "Scanning directory: " . $current_scan_dir_abs . "\n";

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $current_scan_dir_abs, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY,
    );

    /** @var SplFileInfo $file */
    foreach ( $iterator as $file )
    {
        $file_path_abs = $file->getRealPath();

        // Skip if not a PHP file.
        if ( strtolower( $file->getExtension() ) !== 'php' )
        {
            continue;
        }

        // Check against exclude directories.
        $skip_file = false;
        foreach ( $exclude_directories as $exclude_dir_relative_to_plugin )
        {
            $excluded_path_abs = realpath( $plugin_dir . $exclude_dir_relative_to_plugin );
            if ( $excluded_path_abs && stripos( $file_path_abs, $excluded_path_abs ) === 0 )
            {
                $skip_file = true;
                break;
            }
        }

        if ( $skip_file )
        {
             echo "Skipping (excluded): " . $file_path_abs . "\n";
            continue;
        }

        $tokens           = token_get_all( file_get_contents( $file_path_abs ) );
        $namespace        = '';
        $class_like_found = false; // To find class, interface, trait, enum

        for ( $i = 0; $i < count( $tokens ); $i++ )
        {
            if ( is_array( $tokens[ $i ] ) )
            {
                switch ( $tokens[ $i ][ 0 ] )
                {
                    case T_NAMESPACE:
                        $namespace = '';
                        for ( $j = $i + 1; $j < count( $tokens ); $j++ )
                        {
                            if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][ 0 ], [ T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR ], true ) )
                            {
                                $namespace .= $tokens[ $j ][ 1 ];
                            }
                            elseif ( $tokens[ $j ] === ';' )
                            {
                                $i = $j;
                                break;
                            }
                        }
                        break;

                    case T_INTERFACE:
                    case T_TRAIT:
                    case T_ENUM: // T_ENUM requires PHP 8.1+ for the tokenizer
                        $class_like_found = true;
                        break;

                    case T_CLASS:
                        $class_like_found = is_named_class_declaration( $tokens, $i );
                        break;

                    case T_STRING:
                        if ( $class_like_found )
                        {
                            $class_name = $tokens[ $i ][ 1 ];
                            $full_name  = $namespace ? $namespace . '\\' . $class_name : $class_name;

                            $is_plugin_symbol = str_starts_with( $full_name, 'Sentient_Forms_' );

                            if ( $is_plugin_symbol )
                            {
                                if ( isset( $class_map[ $full_name ] ) && $class_map[ $full_name ] !== $file_path_abs )
                                {
                                    echo "Warning: Duplicate class definition found for '$full_name'. Check files:\n";
                                    echo "  - " . $class_map[ $full_name ] . "\n";
                                    echo "  - " . $file_path_abs . "\n";
                                }

                                // Calculate path relative to the class map's directory ($class_map_output_dir)
                                $path_for_map_entry = $file_path_abs;
                                if ( stripos( $path_for_map_entry, $class_map_output_dir . DIRECTORY_SEPARATOR ) === 0 )
                                {
                                    $path_for_map_entry = substr( $path_for_map_entry, strlen( $class_map_output_dir . DIRECTORY_SEPARATOR ) );
                                }
                                else
                                {
                                    // This case should ideally not happen if scan_directories are correctly set up
                                    // relative to where class-map.php is.
                                    // For robustness, one might need a more complex relative path calculation here
                                    // or adjust $scan_directories and $output_file location.
                                    echo "Warning: File $file_path_abs is outside the class map directory $class_map_output_dir. Storing absolute path, which might not be portable.\n";
                                }

                                // Normalize slashes to forward slashes for consistency in the map file.
                                $path_for_map_entry      = str_replace( DIRECTORY_SEPARATOR, '/', $path_for_map_entry );
                                $class_map[ $full_name ] = $path_for_map_entry;
                                // echo "Found: {$full_name} -> {$path_for_map_entry}\n";
                            }
                            $class_like_found = false;
                        }
                        break;
                }
            }
        }
    }
}

if ( empty( $class_map ) )
{
    fwrite( STDERR, "No classes matching the prefix 'Sentient_Forms_' found. Please check scan_directories, file contents, and plugin structure.\n" );
    exit( 1 );
}
else
{
    ksort( $class_map );
    $output_content = "<?php\n";
    $output_content .= "// Sentient Forms Class Map - Auto-generated by build/generate-classmap.php\n";
    $output_content .= "// Do not edit this file manually.\n\n";
    $output_content .= "if ( ! defined( 'ABSPATH' ) )\n";
    $output_content .= "{\n";
    $output_content .= "    exit;\n";
    $output_content .= "}\n\n";
    $output_content .= "return [\n";
    foreach ( $class_map as $class => $path_in_map )
    {
        // $path_in_map is already relative to class-map.php's directory and uses forward slashes.
        $output_content .= "\t'" . addslashes( $class ) . "' => __DIR__ . '/" . addslashes( $path_in_map ) . "',\n";
    }
    $output_content .= "];\n";

    // Ensure the output directory exists
    if ( !is_dir( $class_map_output_dir ) )
    {
        if ( !mkdir( $class_map_output_dir, 0755, true ) )
        {
            echo "Error: Could not create directory for class map: " . $class_map_output_dir . "\n";
            exit( 1 );
        }
    }

    if ( $check_only )
    {
        $current = is_file( $output_file ) ? file_get_contents( $output_file ) : false;
        if ( ! is_string( $current ) || ! hash_equals( $output_content, $current ) )
        {
            fwrite( STDERR, "Class map is stale. Run php build/generate-class-map.php.\n" );
            exit( 1 );
        }
        echo count( $class_map ) . " class-map entries are current.\n";
    }
    elseif ( false !== file_put_contents( $output_file, $output_content ) )
    {
        echo "Class map generated successfully at: " . $output_file . "\n";
        echo count( $class_map ) . " classes, interfaces, enums, and traits mapped.\n";
    }
    else
    {
        fwrite( STDERR, "Error: Could not write class map to: " . $output_file . "\n" );
        exit( 1 );
    }
}

echo "Class map generation finished.\n";

/**
 * Distinguish a named class declaration from ::class and anonymous classes.
 *
 * @param array<int, array{int,string,int}|string> $tokens
 */
function is_named_class_declaration( array $tokens, int $index ): bool
{
    for ( $cursor = $index - 1; $cursor >= 0; $cursor-- )
    {
        $token = $tokens[ $cursor ];
        if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) )
        {
            continue;
        }

        if ( is_array( $token ) && T_READONLY === $token[0] )
        {
            continue;
        }

        if ( ']' === $token )
        {
            $cursor = skip_attribute_group_backward( $tokens, $cursor );
            continue;
        }

        return ! ( is_array( $token ) && in_array( $token[0], [ T_DOUBLE_COLON, T_NEW ], true ) );
    }

    return true;
}

/**
 * Return the cursor immediately before an attribute group ending at $index.
 *
 * @param array<int, array{int,string,int}|string> $tokens
 */
function skip_attribute_group_backward( array $tokens, int $index ): int
{
    $depth = 0;
    for ( $cursor = $index; $cursor >= 0; $cursor-- )
    {
        $token = $tokens[ $cursor ];
        if ( ']' === $token )
        {
            $depth++;
            continue;
        }
        if ( '[' === $token )
        {
            $depth--;
            continue;
        }
        if ( is_array( $token ) && T_ATTRIBUTE === $token[0] )
        {
            $depth--;
            if ( 0 === $depth )
            {
                return $cursor;
            }
        }
    }

    return $index;
}
