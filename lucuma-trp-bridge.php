<?php
/**
 * Plugin Name: Lucuma TRP Bridge
 * Description: Expone la base de traducciones de TranslatePress por REST para poder leer los originales y publicar traducciones revisadas.
 * Version:     1.2.0
 * Author:      Lucuma Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUCUMA_TRP_NS', 'lucuma-trp/v1' );

/** Solo administradores. La autenticacion la resuelve la contrasena de aplicacion. */
function lucuma_trp_can() {
	return current_user_can( 'manage_options' );
}

/**
 * Nombre real de la tabla de diccionario para un par de idiomas.
 * TranslatePress usa {prefix}trp_dictionary_{origen}_{destino} en minusculas.
 */
function lucuma_trp_table( $to, $from = null ) {
	global $wpdb;
	if ( null === $from ) {
		$from = lucuma_trp_default_language();
	}
	return $wpdb->prefix . 'trp_dictionary_' . strtolower( $from ) . '_' . strtolower( $to );
}

function lucuma_trp_settings() {
	$s = get_option( 'trp_settings', array() );
	return is_array( $s ) ? $s : array();
}

function lucuma_trp_default_language() {
	$s = lucuma_trp_settings();
	return isset( $s['default-language'] ) ? $s['default-language'] : 'en_US';
}

function lucuma_trp_languages() {
	$s = lucuma_trp_settings();
	return isset( $s['translation-languages'] ) ? (array) $s['translation-languages'] : array();
}

/** Comprueba que el idioma pedido esta configurado, para no crear tablas fantasma. */
function lucuma_trp_validate_language( $lang ) {
	if ( ! in_array( $lang, lucuma_trp_languages(), true ) ) {
		return new WP_Error(
			'lucuma_trp_bad_language',
			sprintf( 'El idioma "%s" no esta configurado en TranslatePress. Configurados: %s', $lang, implode( ', ', lucuma_trp_languages() ) ),
			array( 'status' => 400 )
		);
	}
	if ( $lang === lucuma_trp_default_language() ) {
		return new WP_Error( 'lucuma_trp_default_language', 'Ese es el idioma por defecto: no tiene diccionario propio.', array( 'status' => 400 ) );
	}
	return true;
}

function lucuma_trp_table_exists( $table ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

/* ------------------------------------------------------------------ *
 *  GET /diag  — que hay realmente en esta instalacion
 * ------------------------------------------------------------------ */
function lucuma_trp_diag() {
	global $wpdb;
	$s   = lucuma_trp_settings();
	$out = array(
		'trp_version'      => defined( 'TRP_PLUGIN_VERSION' ) ? TRP_PLUGIN_VERSION : null,
		'default_language' => lucuma_trp_default_language(),
		'languages'        => lucuma_trp_languages(),
		'machine_translation' => isset( $s['machine-translation'] ) ? $s['machine-translation'] : null,
		'translation_engine'  => isset( $s['translation-engine'] ) ? $s['translation-engine'] : null,
		'trp_query_class'  => class_exists( 'TRP_Query' ),
		'tables'           => array(),
	);

	$like   = $wpdb->esc_like( $wpdb->prefix . 'trp_' ) . '%';
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

	foreach ( (array) $tables as $t ) {
		$cols = $wpdb->get_results( "DESCRIBE `$t`" );
		$out['tables'][ $t ] = array(
			'rows'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ),
			'columns' => wp_list_pluck( $cols, 'Field' ),
		);
		// Reparto por estado, util para saber que hay traducido y como.
		$has_status = in_array( 'status', wp_list_pluck( $cols, 'Field' ), true );
		if ( $has_status ) {
			$out['tables'][ $t ]['by_status'] = $wpdb->get_results(
				"SELECT status, COUNT(*) AS n FROM `$t` GROUP BY status",
				ARRAY_A
			);
		}
	}
	return rest_ensure_response( $out );
}

/* ------------------------------------------------------------------ *
 *  GET /strings  — leer los originales tal y como TranslatePress los guarda
 * ------------------------------------------------------------------ */
function lucuma_trp_get_strings( WP_REST_Request $req ) {
	global $wpdb;

	$lang  = (string) $req->get_param( 'language' );
	$valid = lucuma_trp_validate_language( $lang );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$table = lucuma_trp_table( $lang );
	if ( ! lucuma_trp_table_exists( $table ) ) {
		return new WP_Error( 'lucuma_trp_no_table', "No existe la tabla $table.", array( 'status' => 404 ) );
	}

	$limit  = min( 1000, max( 1, (int) $req->get_param( 'limit' ) ?: 100 ) );
	$offset = max( 0, (int) $req->get_param( 'offset' ) );
	$search = (string) $req->get_param( 'search' );
	$status = $req->get_param( 'status' );

	$where = array( '1=1' );
	$args  = array();

	if ( '' !== $search ) {
		$where[] = 'original LIKE %s';
		$args[]  = '%' . $wpdb->esc_like( $search ) . '%';
	}
	if ( null !== $status && '' !== $status ) {
		$where[] = 'status = %d';
		$args[]  = (int) $status;
	}

	$sql   = "SELECT * FROM `$table` WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d OFFSET %d';
	$args[] = $limit;
	$args[] = $offset;

	$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . implode( ' AND ', array_slice( $where, 0 ) ) );

	return rest_ensure_response(
		array(
			'table'  => $table,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
			'rows'   => $rows,
		)
	);
}

/* ------------------------------------------------------------------ *
 *  POST /translate  — publicar traducciones
 *
 *  Solo ACTUALIZA filas cuyo "original" ya existe en el diccionario:
 *  no inventa originales, porque TranslatePress los crea al rastrear la
 *  pagina y un original inventado nunca llegaria a mostrarse.
 * ------------------------------------------------------------------ */
function lucuma_trp_translate( WP_REST_Request $req ) {
	global $wpdb;

	$lang  = (string) $req->get_param( 'language' );
	$valid = lucuma_trp_validate_language( $lang );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$table = lucuma_trp_table( $lang );
	if ( ! lucuma_trp_table_exists( $table ) ) {
		return new WP_Error( 'lucuma_trp_no_table', "No existe la tabla $table.", array( 'status' => 404 ) );
	}

	$pairs = $req->get_param( 'pairs' );
	if ( ! is_array( $pairs ) || empty( $pairs ) ) {
		return new WP_Error( 'lucuma_trp_no_pairs', 'Falta "pairs": lista de {original, translated}.', array( 'status' => 400 ) );
	}
	if ( count( $pairs ) > 500 ) {
		return new WP_Error( 'lucuma_trp_too_many', 'Maximo 500 pares por llamada.', array( 'status' => 400 ) );
	}

	// status 2 = revisado por humano. TranslatePress no lo pisa con traduccion automatica.
	$status  = null === $req->get_param( 'status' ) ? 2 : (int) $req->get_param( 'status' );
	$dry_run = (bool) $req->get_param( 'dry_run' );

	$res = array( 'table' => $table, 'status_escrito' => $status, 'dry_run' => $dry_run,
	              'actualizados' => 0, 'sin_cambio' => 0, 'ids' => array(),
	              'no_encontrados' => array(), 'errores' => array() );

	foreach ( $pairs as $i => $p ) {
		$orig = isset( $p['original'] ) ? (string) $p['original'] : '';
		$new  = isset( $p['translated'] ) ? (string) $p['translated'] : '';

		if ( '' === $orig || '' === $new ) {
			$res['errores'][] = array( 'i' => $i, 'motivo' => 'original o translated vacio' );
			continue;
		}

		// BINARY es imprescindible: la colacion por defecto de MySQL ignora
		// mayusculas, y TranslatePress guarda como filas distintas el enlace
		// del indice y el encabezado que solo se diferencian en la capitalizacion.
		// Sin BINARY se actualiza la fila equivocada en silencio.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, translated, status FROM `$table` WHERE BINARY original = %s LIMIT 1", $orig ),
			ARRAY_A
		);

		if ( ! $row ) {
			$res['no_encontrados'][] = mb_substr( $orig, 0, 120 );
			continue;
		}
		if ( $row['translated'] === $new && (int) $row['status'] === $status ) {
			$res['sin_cambio']++;
			continue;
		}
		if ( $dry_run ) {
			$res['actualizados']++;
			continue;
		}

		$ok = $wpdb->update(
			$table,
			array( 'translated' => $new, 'status' => $status ),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		if ( false === $ok ) {
			$res['errores'][] = array( 'i' => $i, 'motivo' => $wpdb->last_error );
		} else {
			$res['actualizados']++;
			$res['ids'][] = (int) $row['id'];
		}
	}

	// Sin esto seguirias viendo la version antigua servida por WP Rocket.
	if ( ! $dry_run && $res['actualizados'] > 0 && function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$res['cache_purgada'] = true;
	}

	return rest_ensure_response( $res );
}


/* ------------------------------------------------------------------ *
 *  Slugs traducidos
 *
 *  Viven en dos tablas propias, aparte del diccionario:
 *    trp_slug_originals     id | original | type
 *    trp_slug_translations  id | original_id | translated | language | status
 *
 *  TranslatePress emite por su cuenta un 301 desde la URL con el slug
 *  original hacia la traducida, asi que no hay que crear redirecciones.
 * ------------------------------------------------------------------ */

function lucuma_trp_slug_tables() {
	global $wpdb;
	return array(
		'orig' => $wpdb->prefix . 'trp_slug_originals',
		'tr'   => $wpdb->prefix . 'trp_slug_translations',
	);
}

function lucuma_trp_get_slugs( WP_REST_Request $req ) {
	global $wpdb;

	$lang  = (string) $req->get_param( 'language' );
	$valid = lucuma_trp_validate_language( $lang );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$t = lucuma_trp_slug_tables();
	foreach ( $t as $table ) {
		if ( ! lucuma_trp_table_exists( $table ) ) {
			return new WP_Error( 'lucuma_trp_no_table', "No existe la tabla $table.", array( 'status' => 404 ) );
		}
	}

	$limit  = min( 1000, max( 1, (int) $req->get_param( 'limit' ) ?: 200 ) );
	$offset = max( 0, (int) $req->get_param( 'offset' ) );
	$search = (string) $req->get_param( 'search' );

	$sql  = "SELECT o.id AS original_id, o.original, o.type,
	                t.id AS translation_id, t.translated, t.status
	         FROM `{$t['orig']}` o
	         LEFT JOIN `{$t['tr']}` t ON t.original_id = o.id AND t.language = %s";
	$args = array( $lang );

	if ( '' !== $search ) {
		$sql   .= ' WHERE o.original LIKE %s';
		$args[] = '%' . $wpdb->esc_like( $search ) . '%';
	}
	$sql   .= ' ORDER BY o.id ASC LIMIT %d OFFSET %d';
	$args[] = $limit;
	$args[] = $offset;

	return rest_ensure_response(
		array(
			'language' => $lang,
			'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['orig']}`" ),
			'rows'     => $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ),
		)
	);
}

function lucuma_trp_set_slugs( WP_REST_Request $req ) {
	global $wpdb;

	$lang  = (string) $req->get_param( 'language' );
	$valid = lucuma_trp_validate_language( $lang );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$t     = lucuma_trp_slug_tables();
	$pairs = $req->get_param( 'pairs' );
	if ( ! is_array( $pairs ) || empty( $pairs ) ) {
		return new WP_Error( 'lucuma_trp_no_pairs', 'Falta "pairs": lista de {original|original_id, translated}.', array( 'status' => 400 ) );
	}
	if ( count( $pairs ) > 200 ) {
		return new WP_Error( 'lucuma_trp_too_many', 'Maximo 200 slugs por llamada.', array( 'status' => 400 ) );
	}

	$status  = null === $req->get_param( 'status' ) ? 2 : (int) $req->get_param( 'status' );
	$dry_run = (bool) $req->get_param( 'dry_run' );

	$res = array( 'language' => $lang, 'status_escrito' => $status, 'dry_run' => $dry_run,
	              'insertados' => 0, 'actualizados' => 0, 'sin_cambio' => 0,
	              'no_encontrados' => array(), 'invalidos' => array(), 'errores' => array() );

	foreach ( $pairs as $i => $p ) {
		$new = isset( $p['translated'] ) ? (string) $p['translated'] : '';
		$new = trim( $new, "/ 	
" );

		// Un slug con espacios, mayusculas o barras rompe la URL en silencio.
		if ( '' === $new || $new !== sanitize_title( $new ) ) {
			$res['invalidos'][] = array( 'i' => $i, 'slug' => $new,
			                             'sugerencia' => sanitize_title( $new ) );
			continue;
		}

		if ( isset( $p['original_id'] ) ) {
			$orig = $wpdb->get_row( $wpdb->prepare( "SELECT id, original FROM `{$t['orig']}` WHERE id = %d", (int) $p['original_id'] ), ARRAY_A );
		} else {
			$o    = isset( $p['original'] ) ? trim( (string) $p['original'], '/' ) : '';
			$orig = $wpdb->get_row( $wpdb->prepare( "SELECT id, original FROM `{$t['orig']}` WHERE BINARY original = %s LIMIT 1", $o ), ARRAY_A );
		}

		if ( ! $orig ) {
			$res['no_encontrados'][] = isset( $p['original'] ) ? $p['original'] : ( 'id:' . ( isset( $p['original_id'] ) ? $p['original_id'] : '?' ) );
			continue;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, translated, status FROM `{$t['tr']}` WHERE original_id = %d AND language = %s LIMIT 1", (int) $orig['id'], $lang ),
			ARRAY_A
		);

		if ( $row && $row['translated'] === $new && (int) $row['status'] === $status ) {
			$res['sin_cambio']++;
			continue;
		}
		if ( $dry_run ) {
			$row ? $res['actualizados']++ : $res['insertados']++;
			continue;
		}

		if ( $row ) {
			$ok = $wpdb->update( $t['tr'], array( 'translated' => $new, 'status' => $status ),
			                     array( 'id' => (int) $row['id'] ), array( '%s', '%d' ), array( '%d' ) );
			if ( false === $ok ) {
				$res['errores'][] = array( 'i' => $i, 'motivo' => $wpdb->last_error );
			} else {
				$res['actualizados']++;
			}
		} else {
			$ok = $wpdb->insert( $t['tr'],
			                     array( 'original_id' => (int) $orig['id'], 'translated' => $new,
			                            'language' => $lang, 'status' => $status ),
			                     array( '%d', '%s', '%s', '%d' ) );
			if ( false === $ok ) {
				$res['errores'][] = array( 'i' => $i, 'motivo' => $wpdb->last_error );
			} else {
				$res['insertados']++;
			}
		}
	}

	if ( ! $dry_run && ( $res['insertados'] + $res['actualizados'] ) > 0 ) {
		// Las reglas de reescritura cachean los slugs: sin esto la URL nueva da 404.
		flush_rewrite_rules( false );
		$res['rewrite_flush'] = true;
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$res['cache_purgada'] = true;
		}
	}

	return rest_ensure_response( $res );
}

/* ------------------------------------------------------------------ *
 *  Rutas
 * ------------------------------------------------------------------ */
add_action( 'rest_api_init', function () {
	register_rest_route( LUCUMA_TRP_NS, '/diag', array(
		'methods'             => 'GET',
		'callback'            => 'lucuma_trp_diag',
		'permission_callback' => 'lucuma_trp_can',
	) );

	register_rest_route( LUCUMA_TRP_NS, '/strings', array(
		'methods'             => 'GET',
		'callback'            => 'lucuma_trp_get_strings',
		'permission_callback' => 'lucuma_trp_can',
		'args'                => array(
			'language' => array( 'required' => true, 'type' => 'string' ),
			'limit'    => array( 'type' => 'integer' ),
			'offset'   => array( 'type' => 'integer' ),
			'search'   => array( 'type' => 'string' ),
			'status'   => array( 'type' => 'integer' ),
		),
	) );

	register_rest_route( LUCUMA_TRP_NS, '/translate', array(
		'methods'             => 'POST',
		'callback'            => 'lucuma_trp_translate',
		'permission_callback' => 'lucuma_trp_can',
		'args'                => array(
			'language' => array( 'required' => true, 'type' => 'string' ),
			'pairs'    => array( 'required' => true, 'type' => 'array' ),
			'status'   => array( 'type' => 'integer' ),
			'dry_run'  => array( 'type' => 'boolean' ),
		),
	) );

	register_rest_route( LUCUMA_TRP_NS, '/slugs', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'lucuma_trp_get_slugs',
			'permission_callback' => 'lucuma_trp_can',
			'args'                => array(
				'language' => array( 'required' => true, 'type' => 'string' ),
				'limit'    => array( 'type' => 'integer' ),
				'offset'   => array( 'type' => 'integer' ),
				'search'   => array( 'type' => 'string' ),
			),
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'lucuma_trp_set_slugs',
			'permission_callback' => 'lucuma_trp_can',
			'args'                => array(
				'language' => array( 'required' => true, 'type' => 'string' ),
				'pairs'    => array( 'required' => true, 'type' => 'array' ),
				'status'   => array( 'type' => 'integer' ),
				'dry_run'  => array( 'type' => 'boolean' ),
			),
		),
	) );
} );
