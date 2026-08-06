<?php
/**
 * Shared MediaRef kind classification + HTML render (Q65).
 *
 * Same kinds as assets/js/wtt-media-render.js — for SSR / future frontend page view.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side media display helper (mirrors JS WTTMediaRender).
 */
final class Media_Render {

	/**
	 * i18n keys shared with admin + future frontend enqueue.
	 *
	 * @return array<string, string>
	 */
	public static function i18n(): array {
		return array(
			'mediaEmpty'           => __( 'No media', 'wp-taxonomy-tree' ),
			'mediaKindImage'       => __( 'Image', 'wp-taxonomy-tree' ),
			'mediaKindVideo'       => __( 'Video', 'wp-taxonomy-tree' ),
			'mediaKindAudio'       => __( 'Audio', 'wp-taxonomy-tree' ),
			'mediaKindPdf'         => __( 'PDF', 'wp-taxonomy-tree' ),
			'mediaKindArchive'     => __( 'Archive download', 'wp-taxonomy-tree' ),
			'mediaKindOffice'      => __( 'Office document', 'wp-taxonomy-tree' ),
			'mediaKindText'        => __( 'Text', 'wp-taxonomy-tree' ),
			'mediaKindFile'        => __( 'File download', 'wp-taxonomy-tree' ),
			'mediaKindLink'        => __( 'Link', 'wp-taxonomy-tree' ),
			'mediaPlayVideo'       => __( 'Play video', 'wp-taxonomy-tree' ),
			'mediaPlayAudio'       => __( 'Play audio', 'wp-taxonomy-tree' ),
			'mediaOpenPdf'         => __( 'Open PDF', 'wp-taxonomy-tree' ),
			'mediaDownloadArchive' => __( 'Download archive', 'wp-taxonomy-tree' ),
			'mediaOpenOffice'      => __( 'Open document', 'wp-taxonomy-tree' ),
			'mediaOpenText'        => __( 'Open text', 'wp-taxonomy-tree' ),
			'mediaDownloadFile'    => __( 'Download', 'wp-taxonomy-tree' ),
			'mediaKindsHint'       => __( 'Display depends on MIME / URL (Q65): image, video, audio, PDF, archive, Office, text, file, or link.', 'wp-taxonomy-tree' ),
			'mediaKindsRequired'   => __( 'Select at least one MIME kind — media fields do nothing until a kind is enabled.', 'wp-taxonomy-tree' ),
			'mediaKindsLabel'      => __( 'Allowed MIME kinds', 'wp-taxonomy-tree' ),
			'mediaKindsSelectedHint' => __( 'Rendering only:', 'wp-taxonomy-tree' ),
			'mediaKindPlaceholder' => __( 'Reserved', 'wp-taxonomy-tree' ),
		);
	}

	/**
	 * Enqueue shared CSS/JS for frontend (page/block). Call when a MediaRef is shown.
	 */
	public static function enqueue_assets(): void {
		$ver = defined( 'WTT_VERSION' ) ? WTT_VERSION : '0.0.1';
		wp_enqueue_style(
			'wtt-media-render',
			WTT_PLUGIN_URL . 'assets/css/wtt-media-render.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			'wtt-media-render',
			WTT_PLUGIN_URL . 'assets/js/wtt-media-render.js',
			array(),
			$ver,
			true
		);
		wp_localize_script(
			'wtt-media-render',
			'wttMediaRenderI18n',
			self::i18n()
		);
		wp_add_inline_script(
			'wtt-media-render',
			'if (window.WTTMediaRender) { window.WTTMediaRender.configure({ i18n: window.wttMediaRenderI18n || {} }); }',
			'after'
		);
	}

	/**
	 * @return list<string>
	 */
	public static function kinds(): array {
		return Node_Type::media_kind_keys();
	}

	/**
	 * @param array<string, mixed>|null $ref MediaRef shape.
	 */
	public static function classify_kind( ?array $ref ): string {
		if ( null === $ref || array() === $ref ) {
			return '';
		}
		$mime  = strtolower( (string) ( $ref['mime'] ?? '' ) );
		$url   = (string) ( $ref['url'] ?? '' );
		$file  = (string) ( $ref['filename'] ?? '' );
		$probe = strtolower( '' !== $url ? $url : $file );
		$ext   = self::extension( $probe );

		if ( str_starts_with( $mime, 'image/' ) || preg_match( '/^(png|jpe?g|gif|webp|svg|bmp|ico|avif)$/', $ext ) ) {
			return 'image';
		}
		if ( str_starts_with( $mime, 'video/' ) || preg_match( '/^(mp4|webm|ogv|mov|m4v|avi)$/', $ext ) ) {
			return 'video';
		}
		if ( str_starts_with( $mime, 'audio/' ) || preg_match( '/^(mp3|wav|flac|aac|m4a|oga|ogg)$/', $ext ) ) {
			return 'ogv' === $ext ? 'video' : 'audio';
		}
		if ( 'application/pdf' === $mime || 'pdf' === $ext ) {
			return 'pdf';
		}
		if (
			in_array( $mime, array( 'application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/x-rar-compressed', 'application/vnd.rar', 'application/x-7z-compressed', 'application/gzip', 'application/x-tar' ), true )
			|| preg_match( '/^(zip|rar|7z|tar|gz|tgz)$/', $ext )
		) {
			return 'archive';
		}
		if (
			str_starts_with( $mime, 'application/msword' )
			|| str_starts_with( $mime, 'application/vnd.ms-excel' )
			|| str_starts_with( $mime, 'application/vnd.ms-powerpoint' )
			|| str_starts_with( $mime, 'application/vnd.openxmlformats-officedocument' )
			|| str_starts_with( $mime, 'application/vnd.oasis.opendocument' )
			|| preg_match( '/^(docx?|xlsx?|pptx?|odt|ods|odp)$/', $ext )
		) {
			return 'office';
		}
		if (
			str_starts_with( $mime, 'text/' )
			|| in_array( $mime, array( 'application/json', 'application/xml' ), true )
			|| preg_match( '/^(txt|csv|md|json|xml|html?)$/', $ext )
		) {
			return 'text';
		}
		$source = (string) ( $ref['source'] ?? '' );
		$att    = (int) ( $ref['attachment_id'] ?? 0 );
		if ( 'url' === $source || ( $att <= 0 && '' !== $url ) ) {
			if ( ! preg_match( '/^(pdf|zip|rar|7z|tar|gz|tgz|docx?|xlsx?|pptx?|odt|ods|odp|png|jpe?g|gif|webp|svg|bmp|ico|avif|mp4|webm|ogg|ogv|mov|m4v|avi|mp3|wav|flac|aac|m4a|txt|csv|md|json|xml|html?)$/', $ext ) ) {
				return 'link';
			}
		}
		return 'file';
	}

	/**
	 * @param array<string, mixed>|null $ref MediaRef.
	 * @param array{compact?:bool}      $args Options.
	 */
	public static function render_html( ?array $ref, array $args = array() ): string {
		$compact = ! empty( $args['compact'] );
		$i18n    = self::i18n();

		if ( null === $ref || array() === $ref ) {
			return '<span class="wtt-media-empty">' . esc_html( $i18n['mediaEmpty'] ) . '</span>';
		}

		$kind  = self::classify_kind( $ref );
		$href  = (string) ( $ref['url'] ?? '' );
		$label = self::display_label( $ref, $i18n );
		$live  = self::is_live_href( $href );
		$cls   = 'wtt-media-preview' . ( $compact ? ' wtt-media-preview--compact' : '' ) . ( '' !== $kind ? ' wtt-media-preview--' . $kind : '' );
		$badge = '<span class="wtt-media-preview__kind">' . esc_html( self::kind_label( $kind, $i18n ) ) . '</span>';

		if ( 'image' === $kind && ( ! empty( $ref['thumb'] ) || '' !== $href ) ) {
			$src = ! empty( $ref['thumb'] ) ? (string) $ref['thumb'] : $href;
			$img = '<img class="wtt-media-preview__thumb" src="' . esc_url( $src ) . '" alt="' . esc_attr( $label ) . '" />';
			if ( $live ) {
				$img = '<a class="wtt-media-preview__link" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">' . $img . '</a>';
			}
			return '<div class="' . esc_attr( $cls ) . '">' . $img . $badge . '</div>';
		}

		if ( 'video' === $kind && $live && ! $compact ) {
			$type = esc_attr( (string) ( $ref['mime'] ?? 'video/mp4' ) );
			return '<div class="' . esc_attr( $cls ) . '"><video class="wtt-media-preview__av" controls preload="metadata"><source src="' . esc_url( $href ) . '" type="' . $type . '" /></video>' . $badge . '</div>';
		}

		if ( 'audio' === $kind && $live && ! $compact ) {
			$type = esc_attr( (string) ( $ref['mime'] ?? 'audio/mpeg' ) );
			return '<div class="' . esc_attr( $cls ) . '"><audio class="wtt-media-preview__av" controls preload="metadata"><source src="' . esc_url( $href ) . '" type="' . $type . '" /></audio>' . $badge . '</div>';
		}

		$action_class = 'wtt-media-preview__action wtt-media-preview__action--' . ( '' !== $kind ? $kind : 'file' );
		$text         = self::action_text( $kind, $label, $i18n );
		if ( $live ) {
			$dl   = ( 'archive' === $kind || 'file' === $kind ) ? ' download="' . esc_attr( $label ) . '"' : '';
			$body = '<a class="' . esc_attr( $action_class ) . '" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer"' . $dl . '>' . esc_html( $text ) . '</a>';
		} else {
			$body = '<span class="' . esc_attr( $action_class ) . '">' . esc_html( $text ) . '</span>';
		}

		return '<div class="' . esc_attr( $cls ) . '">' . $body . $badge . '</div>';
	}

	/**
	 * @param array<string, string> $i18n Labels.
	 */
	private static function display_label( array $ref, array $i18n ): string {
		if ( ! empty( $ref['filename'] ) ) {
			return (string) $ref['filename'];
		}
		if ( ! empty( $ref['url'] ) ) {
			return (string) $ref['url'];
		}
		if ( ! empty( $ref['attachment_id'] ) ) {
			return '#' . (int) $ref['attachment_id'];
		}
		return $i18n['mediaEmpty'];
	}

	/**
	 * @param array<string, string> $i18n Labels.
	 */
	private static function kind_label( string $kind, array $i18n ): string {
		$map = array(
			'image'   => $i18n['mediaKindImage'],
			'video'   => $i18n['mediaKindVideo'],
			'audio'   => $i18n['mediaKindAudio'],
			'pdf'     => $i18n['mediaKindPdf'],
			'archive' => $i18n['mediaKindArchive'],
			'office'  => $i18n['mediaKindOffice'],
			'text'    => $i18n['mediaKindText'],
			'file'    => $i18n['mediaKindFile'],
			'link'    => $i18n['mediaKindLink'],
		);
		return $map[ $kind ] ?? $kind;
	}

	/**
	 * @param array<string, string> $i18n Labels.
	 */
	private static function action_text( string $kind, string $label, array $i18n ): string {
		return match ( $kind ) {
			'video'   => $i18n['mediaPlayVideo'] . ' — ' . $label,
			'audio'   => $i18n['mediaPlayAudio'] . ' — ' . $label,
			'pdf'     => $i18n['mediaOpenPdf'] . ' — ' . $label,
			'archive' => $i18n['mediaDownloadArchive'] . ' — ' . $label,
			'office'  => $i18n['mediaOpenOffice'] . ' — ' . $label,
			'text'    => $i18n['mediaOpenText'] . ' — ' . $label,
			'file'    => $i18n['mediaDownloadFile'] . ' — ' . $label,
			'link', 'image' => $label,
			default   => $label,
		};
	}

	private static function is_live_href( string $href ): bool {
		return '' !== $href && ! str_starts_with( $href, '#' ) && ! str_starts_with( $href, 'data:' );
	}

	private static function extension( string $probe ): string {
		$path = explode( '?', $probe, 2 )[0];
		if ( ! preg_match( '/\.([a-z0-9]+)$/i', $path, $m ) ) {
			return '';
		}
		return strtolower( $m[1] );
	}
}
