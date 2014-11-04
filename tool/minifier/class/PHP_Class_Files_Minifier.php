<?php
/**
 * PHP Class Files Minifier
 * 
 * Provides plugin and theme developers with simpler means of creating option pages, custom post types, ant meta boxes. 
 * 
 * @author      Michael Uno <michael@michaeluno.jp>
 * @copyright   2013-2014 (c) Michael Uno
 * @license     MIT	<http://opensource.org/licenses/MIT>
 */
if ( ! class_exists( 'PHP_Class_Files_Script_Generator_Base' ) ) {
	require( dirname( dirname( dirname( __FILE__ ) ) ) . '/php_class_files_script_generator/PHP_Class_Files_Script_Generator_Base.php' );
}

/**
 * Creates a minified version of PHP scripts from the given PHP class directory.
 * 
 * It collects PHP class files and make them into one and removes PHP comments except the specified class docBlock.
 * 
 * @remark     The parsed class file must have a name of the class defined in the file.
 * @version    1.0.1
 */
class PHP_Class_Files_Minifier extends PHP_Class_Files_Script_Generator_Base {
	
	static protected $_aStructure_Options = array(
	
		'header_class_name' => '',
		'header_class_path' => '',		
		'output_buffer'     => true,
		'header_type'       => 'DOCBLOCK',	
		'exclude_classes'   => array(),
		'css_heredoc_keys'  => array( 'CSSRULES' ),     // 1.1.0+
		// Search options
		'search'	=>	array(
			'allowed_extensions' => array( 'php' ),	// e.g. array( 'php', 'inc' )
			'exclude_dir_paths'  => array(),
			'exclude_dir_names'  => array(),
			'is_recursive'       => true,
		),		
		
	);
	
	/**
	 * 
	 * @param string    $sSourceDirPath     The target directory path.
	 * @param string    $sOutputFilePath    The destination file path.
	 * @param array     $aOptions           The options array. It takes the following arguments.
	 *  - 'header_class_name'   : string    the class name that provides the information for the heading comment of the result output of the minified script.
	 *  - 'header_class_path'   : string    (optional) the path to the header class file.
	 *  - 'output_buffer'       : boolean	whether or not output buffer should be printed.
	 *  - 'header_type'         : string	whether or not to use the docBlock of the header class; otherwise, it will parse the constants of the class. 
	 *  - 'exclude_classes'     : array		an array holding class names to exclude.
     *  - 'css_heredoc_keys'    : array     [1.1.0+] (optional) an array holding heredoc keywords used to assign CSS rules to a variable.
	 *  - 'search'              : array		the arguments for the directory search options.
	 * 	The accepted values are 'CONSTANTS' or 'DOCBLOCK'.
	 * <h3>Example</h3>
	 * <code>array(
	 *		'header_class_name' => 'HeaderClassForMinifiedVerions',
	 *		'file_pettern'      => '/.+\.(php|inc)/i',
	 *		'output_buffer'     => false,
	 *		'header_type'       => 'CONSTANTS',
	 * 
	 * )</code>
	 * 
	 * When false is passed to the 'use_docblock' argument, the constants of the header class must include 'Version', 'Name', 'Description', 'URI', 'Author', 'CopyRight', 'License'. 
	 * <h3>Example</h3>
	 * <code>class TaskScheduler_Registry_Base {
	 * 		const Version       = '1.0.0b08';
	 * 		const Name          = 'Task Scheduler';
	 * 		const Description   = 'Provides an enhanced task management system for WordPress.';
	 * 		const URI           = 'http://en.michaeluno.jp/';
	 * 		const Author        = 'miunosoft (Michael Uno)';
	 * 		const AuthorURI     = 'http://en.michaeluno.jp/';
	 * 		const CopyRight     = 'Copyright (c) 2014, <Michael Uno>';
	 * 		const License       = 'GPL v2 or later';
	 * 		const Contributors  = '';
	 * }</code>
	 */
	public function __construct( $asScanDirPaths, $sOutputFilePath, array $aOptions=array() ) {

		$aOptions           = $aOptions + self::$_aStructure_Options;
		$aOptions['search'] = $aOptions['search'] + self::$_aStructure_Options['search'];
		$_sCarriageReturn   = php_sapi_name() == 'cli' ? PHP_EOL : '<br />';
		$_aScanDirPaths     = ( array ) $asScanDirPaths;
		
			if ( $aOptions['output_buffer'] ) {
				echo 'Searching files under the directory: ' . implode( ', ', $_aScanDirPaths ) . $_sCarriageReturn;
			}
		
		/* Store the file contents into an array. */
		$_aFiles = $this->_formatFileArray( $this->_getFileLists( $_aScanDirPaths, $aOptions['search'] ) );
		unset( $_aFiles[ pathinfo( $sOutputFilePath, PATHINFO_FILENAME ) ] );	// it's possible that the minified file also gets loaded but we don't want it.

			if ( $aOptions['output_buffer'] ) {				
				echo sprintf( 'Found %1$s file(s)', count( $_aFiles ) ) . $_sCarriageReturn;
				foreach ( $_aFiles as $_aFile ) {
					echo $_aFile['path'] . $_sCarriageReturn;
					// echo implode( ', ', $_aFile['defined_classes'] ) . $_sCarriageReturn;
				}
			}			
	   
		/* Generate the output script header comment */
		$_sHeaderComment = $this->_getHeaderComment( $_aFiles, $aOptions );
			if ( $aOptions['output_buffer'] ) {
				echo( $_sHeaderComment ) . $_sCarriageReturn;
			}		
		
		/* Sort the classes - in some PHP versions, parent classes must be defined before extended classes. */
		$_aFiles = $this->sort( $_aFiles, $aOptions['exclude_classes'] );
		
			if ( $aOptions['output_buffer'] ) {
				echo sprintf( 'Sorted %1$s file(s)', count( $_aFiles ) ) . $_sCarriageReturn;			
			}		
            
        /* Minify CSS Rules in variables defined with the heredoc syntax [1.1.0+] */
        $_aFiles = $this->minifyCSS( $_aFiles, $aOptions['css_heredoc_keys'], $aOptions['output_buffer'] ? $_sCarriageReturn : false );
             
		
		/* Write to a file */
		$this->write( $_aFiles, $sOutputFilePath, $_sHeaderComment );
		
	}
		
    /**
     * Minify CSS Rules in variables defined with the heredoc syntax. 
     * @since       1.1.0
     * 
     * @param   array           $aFiles
     * @param   array           $aHereDocKeys
     * @param   boolean|string  $bsCarriageReturns      If the output buffer is enabled, the carriage return value; otherwise, false.
     */ 
    public function minifyCSS( array $aFiles, array $aHereDocKeys=array(), $bsCarriageReturn=false ) {

        $_iMinified = $_iCount = 0;
        foreach( $aFiles as $_sClassName => &$_aFile ) {
          
            foreach( $aHereDocKeys as $_sHereDocKey ) {

                $_aFile['code'] = preg_replace_callback( 
                    "/\s?+\K(<<<{$_sHereDocKey}[\r\n])(.+?)([\r\n]{$_sHereDocKey};(\s+)?[\r\n])/ms",   // needle
                    array( $this, '_replyToMinifyCSSRules' ),                               // callback
                    $_aFile['code'],                                                         // haystack
                    -1,  // limit -1 for no limit
                    $_iCount
                );
                $_iMinified = $_iCount ? $_iMinified + $_iCount : $_iMinified;
                
            }
            
        }
        
        if ( $bsCarriageReturn ) {
            echo sprintf( 'Minified CSS Rules in %1$s of heredoc variable(s).', $_iMinified ) . $bsCarriageReturn;			
        }
        
        return $aFiles;
        
    }
        /**
         * The callback function to modify the CSS rules defined in heredoc variable assignments.
         * 
         * @since       1.1.1
         */
        public function _replyToMinifyCSSRules( $aMatch ) {
 
            if ( ! isset( $aMatch[ 1 ], $aMatch[ 2 ], $aMatch[ 3 ] ) ) {
                return $aMatch[ 0 ];
            }                   
            $_sCSSRules = $aMatch[ 2 ];
            $_sCSSRules = str_replace( 
                array( "\r\n", "\r", "\n", "\t", '  ', '    ', '    '),  // needle - remove tabs, spaces, newlines, etc.
                '',     // replace
                preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $_sCSSRules )  // haystack - comments removed
            );
            return '"' . $_sCSSRules . '"; '; 
            
        }
            
	public function sort( array $aFiles, array $aExcludingClassNames ) {
		
		foreach( $aFiles as $_sClassName => $_aFile ) {
			if ( in_array( $_sClassName, $aExcludingClassNames ) ) {
				unset( $aFiles[ $_sClassName ] );
			}
		}
		return $this->_resolveDependent( $aFiles );
	
	}
		/**
		 * This sorts the parsed PHP classes by making parent classes come earlier.
		 * 
		 * In some PHP versions, extended class must be declared after the parent class. 
		 */
		private function _resolveDependent( array $aFiles ) {
		
			/* Append the dependent code to the dependent one and remove the dependent. */
			$aFiles = $this->_moveDependant( $aFiles );
			
			/* Unset the defendant element */
			foreach ( $aFiles as $sClassName => $aFile ) {
				if ( $aFile['code'] ) { continue; }
				unset( $aFiles[ $sClassName ] );
			}
			
			/* Make sure dependant elements no longer exist.*/
			$_iDependency = 0;
			foreach ( $aFiles as $sClassName => $aFile ) {
				if ( $aFile['dependency'] && isset( $aFiles[ $aFile['dependency'] ] ) ) {
					$_iDependency++;
				}
			}
			if ( $_iDependency ) {
				return $this->_resolveDependent( $aFiles );
			}
			return $aFiles;
			
		}
		private function _moveDependant( $aFiles ) {
			
			$iMoved = 0;
			foreach( $aFiles as $sClassName => &$aFile ) {
			
				if ( ! $aFile['dependency'] ) continue;
				if ( ! isset( $aFiles[ $aFile['dependency'] ] ) ) continue;	// it can be an external components.
				if ( ! $aFile['code'] ) continue;
				$aFiles[ $aFile['dependency'] ]['code'] .= $aFile['code'];
				$aFile['code'] = '';
				$iMoved++;
				
			}
			if ( $iMoved ) {
				$aFiles = $this->_moveDependant( $aFiles );
			}
			return $aFiles;
			
		}
		
	public function write( array $aFiles, $sOutputFilePath, $sHeadingComment ) {
			
		$_aData = array();
		
		// Create a heading.
		$_aData[] = mb_convert_encoding( '<?php ' . PHP_EOL . $sHeadingComment . ' ', 'UTF-8', 'auto' );		
		foreach( $aFiles as $_aFile ) {
			$_aData[] = mb_convert_encoding( $_aFile['code'], 'UTF-8', 'auto' );
		}
		
		// Remove the existing file.
		if ( file_exists( $sOutputFilePath ) ) {
			unlink( $sOutputFilePath );
		}
		
		// Write to a file.
		file_put_contents( $sOutputFilePath, implode( '', $_aData ), FILE_APPEND | LOCK_EX );
		
	}

}