<?php
/**
 * @file
 * @author Niklas Laxström
 * @license MIT
 */

namespace MediaWiki\Extension\FileSystemImageServer\Specials;

use Html;
use Message;
use SpecialPage;
use MediaWiki\MediaWikiServices;

class SpecialFSIS extends SpecialPage {
	public function __construct() {
		parent::__construct( 'FSIS' );
	}

	public function isIncludable() {
		return true;
	}

	public function isListed() {
		return false;
	}

	public function execute( $par ) {
		global $wgFSISGroups;

		$out = $this->getOutput();
		$req = $this->getRequest();

		$group = $req->getText( 'g' );
		$filename = $req->getText( 'f' );

		if ( !isset( $wgFSISGroups[ $group ] ) ) {
			$this->showError( 400, $this->msg( 'fsis-error-unknowngroup' ) );
			return;
		}

		$config = $wgFSISGroups[ $group ];
		if ( isset( $config[ 'right' ] ) && !$this->including() && !$this->getUser()->isAllowed( $config[ 'right' ] ) ) {
			// Possible attempt at path traversal or misconfiguration
			$this->showError( 403, $this->msg( 'fsis-error-unauthorized' ) );
			return;
		}

		$fallback = $config[ 'fallback' ] ?? null;

		$pathPrefix = realpath( $config[ 'path' ] );
		$path = realpath( $pathPrefix . '/' . $filename );
		if ( !$path || substr( $path, 0, strlen( $pathPrefix ) + 1 ) !== "$pathPrefix/" ) {
			// Possible attempt at path traversal or misconfiguration
			$this->showError( 404, $this->msg( 'fsis-error-unknowfile' ), $fallback );
			return;
		}

		if ( !is_readable( $path ) ) {
			// File exists (realpath returns false if not), but cannot read.
			// Using a different code to help debugging.
			$this->showError( 500, $this->msg( 'fsis-error-unknowfile' ), $fallback );
			return;
		}


		$type = $this->getMimeType( $path );
		if ( !in_array( $type, (array)($config[ 'mimetypes' ] ?? []) ) ) {
			$this->showError( 500, $this->msg( 'fsis-error-unknowfile' ), $fallback );
			return;
		}

		if ( $this->including() ) {
			$urlParams = [
				'g' => $group,
				'f' => $filename,
			];

			$url = $this->getPageTitle()->getLocalUrl( $urlParams );

			$imgParams = [
				'width' => $req->getInt( 'width' ),
				'height' => $req->getInt( 'height' ),
				'alt' => $req->getText( 'alt' ),
				'title' => $req->getText( 'title' ),
				'src' => $url,
			];

			$imgParams = array_filter( $imgParams );

			$this->getOutput()->addHTML(
				Html::rawElement(
					'a',
					[ 'href' => $url ],
					Html::element( 'img', $imgParams )
				)
			);
		} else {
			$this->getOutput()->disable();
			header( "Content-Type: $type" );
			header( 'Cache-Control: private, max-age=3600' );
			header( 'Expires: ' . wfTimestamp( TS_RFC2822, time() + 3600 ) );
			header( 'Content-Length: ' . filesize( $path ) );
			readfile( $path );
		}
	}

	private function getMimeType( $path ) {
		$magic = MediaWikiServices::getInstance()->getMimeAnalyzer();
		$ext = pathinfo( $path, PATHINFO_EXTENSION );
		$type = $magic->guessMimeType( $path, false );
		$type = $magic->improveTypeFromExtension( $type, $ext );
		return $type;
	}

	private function showError( int $statusCode, Message $msg, $fallback = null ) {
		if ( $this->including() ) {
			$this->getOutput()->addWikiText( "<div class='errorbox'>{$msg->plain()}</div>" );
		} elseif ( $fallback ) {
			$this->getOutput()->disable();
			$type = $this->getMimeType( $fallback );
			header( "Content-Type: $type" );
			header( 'Content-Length: ' . filesize( $fallback ) );
			readfile( $fallback );
		} else {
			$this->getOutput()->disable();
			header( 'Content-Type: text/plain' );
			http_response_code( $statusCode );
			echo $msg->plain();
		}
	}
}
