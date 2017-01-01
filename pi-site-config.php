<?php
add_action( 'muplugins_loaded', 'PISiteConfig::init' );

/**
 * WordPress MU Plugin to configure plugins and redirects with a file based configuration.
 */
final class PISiteConfig
{
    private static $data = array();

    private function __construct() { }

    /**
     * Gibt den Wert für den spezifizierten Key zurück. Wird er nicht gefunden, wird $default zurück gegeben.
     *
     * @param string $key Der Schlüssel, dessen Wert zurück gegeben werden soll.
     * @param mixed $default Der Wert der zurück gegeben wird, wenn $key nicht vorhanden ist.
     */
    public static function get( $key, $default = null )
    {
        $key = strtolower( $key );
        if ( isset( self::$data[ $key ] ) ) {
            return self::$data[ $key ];
        }
        return $default;
    }

    /**
     * Injiziert die Konfiguration via assoziativem Array.
     *
     * @param array $data Die Konfiguration als assoziativer Array.
     */
    public static function set( array $data )
    {
        foreach ( $data as $key => $value ) {
            self::$data[ strtolower( $key ) ] = $value;
        }
    }

    /**
     * Initialisiert die Konfiguration für die aktuelle Seite.
     */
    public static function init()
    {
        // Already initialized?
        if ( ! empty( self::$data ) ) {
            return;
        }

        $configFileName = WP_CONTENT_DIR . '/site-config-' . get_current_blog_id() . '.php';
        if ( ! file_exists( $configFileName ) ) {
            return;
        }

        include $configFileName;

        $configurator = new PISiteConfig();

        if ( isset( self::$data['sitewideplugins'] ) ) {
            add_filter( 'pre_site_option_active_sitewide_plugins', array( $configurator, 'overrideSidewidePlugins' ) );
        }

        if ( isset( self::$data['plugins'] ) ) {
            add_filter( 'pre_option_active_plugins', array( $configurator, 'overridePlugins' ) );
        }

        if ( isset( self::$data['redirects'] ) ) {
            add_action( 'wp', array( $configurator, 'maybeRedirect' ), 0 );
        }
    }

    public function overrideSidewidePlugins()
    {
        $configuredPlugins = array();
        foreach ( PISiteConfig::get( 'sitewideplugins' ) as $plugin => $isActive ) {
            if ( $isActive ) {
                $configuredPlugins[] = $plugin;
            }
        }
        return $configuredPlugins;
    }

    public function overridePlugins()
    {
        $configuredPlugins = array();
        foreach ( PISiteConfig::get( 'plugins' ) as $plugin => $isActive ) {
            if ( $isActive ) {
                $configuredPlugins[] = $plugin;
            }
        }
        return $configuredPlugins;
    }

    public function maybeRedirect()
    {
        global $wp_query;

        // Im Adminbereich haben wir nichts zu suchen
        if ( $wp_query->is_admin ) {
            return;
        }

        // Wenn es keine 404 ist, leiten wir auch nicht weiter, um
        // keine Performance bei gültigen Seitenaufrufen zu verlieren
        if ( ! $wp_query->is_404 ) {
            return;
        }

        // Uns interessiert nur der main query
        if ( ! $wp_query->is_main_query() ) {
            return;
        }

        // Abbrechen, falls Filter in dieser Anfrage unterdrückt werden
        if ( $wp_query->get( 'suppress_filters' ) ) {
            return;
        }

        foreach ( PISiteConfig::get( 'redirects' ) as $request => $target ):

            if ( empty( $request ) or empty( $target ) ) {
                continue;
            }

            // Beginnt die aufgerufene Seite mit dem Redirect?
            if ( 0 === strpos( $_SERVER['REQUEST_URI'], $request ) ):

                // hängt noch was an der URL dran, wie z.B. /page/2/ ?
                if ( $_SERVER['REQUEST_URI'] != $request ) {
                    $target .= preg_replace( '#^(' . preg_quote( $request ) . ')#i', '', $_SERVER['REQUEST_URI'] );
                }

                wp_redirect( esc_url_raw( $target ), 301 );
                exit();

            // Wurde ein regulärer Ausdruck hinterlegt?
            elseif ( '#' === $request[0] ):

                $matches = array();
                if ( preg_match( $request, $_SERVER['REQUEST_URI'], $matches ) ):

                    // Wenn es mehr als 1 Ergebnis gibt, wurde mind. eine
                    // Backreference im Pattern angegeben, mit denen wir mit
                    // $1, $2, etc. ersetzen wollen
                    if ( count( $matches ) > 1 ):
                        for ( $i = 1; $i < count( $matches ); ++ $i ):
                            $target = str_replace( '$' . $i, $matches[ $i ], $target );
                        endfor;
                    endif;

                    wp_redirect( esc_url_raw( $target ), 301 );
                    exit();

                endif;

            endif;

        endforeach;
    }
}