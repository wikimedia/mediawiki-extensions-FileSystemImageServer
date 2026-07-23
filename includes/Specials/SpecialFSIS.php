<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\FileSystemImageServer\Specials;

use MediaWiki\Html\Html;
use MediaWiki\Permissions\PermissionManager;
use Message;
use MimeAnalyzer;
use SpecialPage;

/*
 * @author Niklas Laxström
 * @license MIT
 */
class SpecialFSIS extends SpecialPage {
	private MimeAnalyzer $mimeAnalyzer;
	private PermissionManager $permissionManager;

	public function __construct(
		MimeAnalyzer $mimeAnalyzer,
		PermissionManager $permissionManager
	) {
		parent::__construct( 'FSIS' );
		$this->mimeAnalyzer = $mimeAnalyzer;
		$this->permissionManager = $permissionManager;
	}

	/** @inheritDoc */
	public function isIncludable(): bool {
		return true;
	}

	/** @inheritDoc */
	public function isListed(): bool {
		return false;
	}

	/** @inheritDoc */
	public function execute( $par ): void {
		$fsisConfig = $this->getConfig()->get( 'FSISGroups' );

		$outputPage = $this->getOutput();
		$req = $this->getRequest();

		$group = $req->getText( 'g' );
		$filename = $req->getText( 'f' );

		if ( !isset( $fsisConfig[ $group ] ) ) {
			$this->showError( 400, $this->msg( 'fsis-error-unknowngroup' ) );
			return;
		}

		$config = $fsisConfig[ $group ];
		if (
			   isset( $config[ 'right' ] )
			&& !$this->including()
			&& !$this->permissionManager->userHasRight( $this->getUser(), $config[ 'right' ] )
		) {
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

		// @phan-suppress-next-line SecurityCheck-PathTraversal
		$type = $this->getMimeType( $path );
		if ( !in_array( $type, (array)( $config[ 'mimetypes' ] ?? [] ) ) ) {
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

			$outputPage->addHTML(
				Html::rawElement(
					'a',
					[ 'href' => $url ],
					Html::element( 'img', $imgParams )
				)
			);
		} else {
			$outputPage->disable();
			$response = $req->response();
			$response->header( "Content-Type: $type" );
			$response->header( 'Cache-Control: private, max-age=3600' );
			$response->header( 'Expires: ' . wfTimestamp( TS_RFC2822, time() + 3600 ) );
			$response->header( 'Content-Length: ' . filesize( $path ) );
			readfile( $path );
		}
	}

	private function getMimeType( string $path ): string {
		$ext = pathinfo( $path, PATHINFO_EXTENSION );
		$type = $this->mimeAnalyzer->guessMimeType( $path, false );
		return $this->mimeAnalyzer->improveTypeFromExtension( $type, $ext ) ?? $type;
	}

	private function showError( int $statusCode, Message $msg, ?string $fallback = null ): void {
		$outputPage = $this->getOutput();
		if ( $this->including() ) {
			$outputPage->addWikiTextAsInterface( Html::errorBox( $msg->plain() ) );
		} elseif ( $fallback ) {
			$outputPage->disable();
			$response = $this->getRequest()->response();
			$type = $this->getMimeType( $fallback );
			$response->header( "Content-Type: $type" );
			$response->header( 'Content-Length: ' . filesize( $fallback ) );
			readfile( $fallback );
		} else {
			$outputPage->disable();
			$response = $this->getRequest()->response();
			$response->statusHeader( $statusCode );
			$response->header( 'Content-Type: text/plain' );
			// @phan-suppress-next-line SecurityCheck-XSS
			echo $msg->plain();
		}
	}
}
