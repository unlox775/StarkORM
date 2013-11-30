#!/usr/bin/php 
<?php

$syntax = 
"Syntax: propose_objects_from_dump [options]
  Options:
    -v --verbose               Be loud
    -o --output                Instaed of printing php classes to STDOUT, create file in output directory.  If not exists it will be created
    -g --add_get_where         Add the static get_where() function to each class
    -c --add_get_where_cached  Add the static get_where_cached() function to each class
    -n --no_examples           Don't add example (commented-out) relations
    -h --help                  This message
  ";
$output_dir = null;
$verbose = false;
$flags = array();
for ($i = 0 ; $i < count($argv) ; $i++) {
    if ( preg_match('/^-(\w+)$/', $argv[$i], $m) ) {
        $letters = str_split( $m[1] );
        foreach ( $letters as $ii => $let ) {
            switch ($let) {
            case 'c':
                $flags['add_get_where_cached'] = true;
            case 'g':
                $flags['add_get_where'] = true;
                break;
            case 'n':
                $flags['no_examples'] = true;
                break;
            case 'v':
                $verbose = true;
                break;
            case 'h':
                die( $syntax );
            case 'o':
                if ( ($ii+1) == count($letters) && ($i+1) < count($argv) ) {
                    $i++;
                    $output_dir = $argv[$i];
                }
                else { die( "Invalid output dir\n\n$syntax" ); }
                break;
            }
        }
    }
    else if ( preg_match('/^--(\w+)(?:=(.+))?$/', $argv[$i], $m) ) {
        switch ($m[1]) {
        case 'help':
            die( $syntax );
        case 'add_get_where_cached':
            $flags['add_get_where'] = true;
        case 'add_get_where':
            $flags['add_get_where'] = true;
		case 'no_examples':
			$flags['no_examples'] = true;
			break;
        case 'verbose':
            $verbose = true;
            break;
        case 'output':
            if ( isset($m[2]) ) {
                $output_dir = $m[2];
            }
            else { die( "Invalid output dir\n\n$syntax" ); }
            break;
        }
    }
}

$tables = array();

$table = null;
$mode = 'pre';
while ( $line = fgets(STDIN) ) {
    if ($mode == 'pre') {
        ///  OPEN TABLE
        if ( preg_match('/^\s*CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?\s*\(/i',$line,$m) ) {
            $mode = 'table';
            $table = $m[1];
        }
        else continue;
    }
    else if ( $mode == 'table' ) {
        ///  PRIMARY KEY
        if ( preg_match('/^PRIMARY KEY\s*\((`?\w+`?(?:,`?\w+`?)*)\)/i',trim($line),$m) ) {
            $tables[ $table ]['pkey'] = explode(',',preg_replace('/[\s`]+/','',$m[1]));
        }
        ///  PRIMARY KEY
        else if ( preg_match('/^(PRIMARY|KEY|CONSTRAINT|UNIQUE)/i',trim($line),$m) ) {
            continue;
        }
        ///  COLUMN
        else if ( preg_match('/^`?(\w+)`?\s*(\S[^,]+),?$/',trim($line),$m) ) {
            list( $x, $col, $params ) = $m;
            $tables[ $table ]['columns'][ $col ] = $params;

            if ( strpos(strtoupper($params), 'PRIMARY KEY' ) !== false ) {
                $tables[ $table ]['pkey'] = array($col);
            }
        }
        else if ( preg_match('/^\)/i',trim($line),$m) ) {
            $mode = 'pre';
            $table = null;
        }
    }
}

if ( ! empty( $output_dir ) && ! is_dir( $output_dir ) ) {
    mkdir($output_dir, 0755, true);
    if ( ! is_dir( $output_dir ) ) {
        die("Could not create output directory: $php_errormsg\n\n$syntax");
    }
}

foreach ( $tables as $table => $def ) {
    $classname = to_camel_case($table, true);
    
	///  DEFAULTS: 
	if ( empty( $tables[ $table ]['pkey'] ) ) {
		foreach ( array('id','idx') as $suggested_pkey ) 
			if ( isset( $tables[ $table ]['columns'][ $suggested_pkey ] ) )
				$tables[ $table ]['pkey'] = array( $suggested_pkey );

		if ( empty( $tables[ $table ]['pkey'] ) )
			$tables[ $table ]['pkey'] = array('Error: No Primary Key defined in SQL');
	}


    $fh = STDOUT;
    if ( ! empty( $output_dir ) ) {

		if ( $verbose ) fwrite(STDERR, "\nWriting $output_dir/". $classname .".class.php ...");
        $fh = fopen("$output_dir/". $classname .".class.php",'w');
        if ( ! $fh ) die("Could not open $output_dir/". $classname .".class.php for writing: $php_errormsg\n\n$syntax");
    }
        
    fwrite( $fh, "<?php

require_once('models/StarkORMLocal.php');

class $classname extends StarkORMLocal {
    protected \$table       = '$table';
    protected \$primary_key = array( '". join("','",$tables[ $table ]['pkey']) ."' );
    protected \$schema = array( "
             );

    foreach ( $tables[ $table ]['columns'] as $col => $params ) {
        fwrite( $fh, "'$col'\t\t\t\t=> array(  ),
                               "
                 );
    }

    fwrite( $fh, ");
    protected \$relations = 
        array( "
			);

	if ( ! $flags['no_examples'] ) {
		fwrite( $fh, "

/* ---  Stark ORM Relation Examples ---
               'example' => array( 'relationship' => 'has_one',
                                   'include'      => 'model/example.class.php', # A file to require_once(), (should be in include_path)
                                   'class'        => 'Example',                 # The class name
                                   'columns'      => 'example_id',              # local cols to get the PKey for the new object (can be array if >1 col..)
                                   ),

               'examples' => array( 'relationship'        => 'has_many',
                                    'include'             => 'model/Example.class.php',  # A file to require_once(), (should be in include_path)
                                    'class'               => 'Example',                  # The class name
                                    'foreign_table'       => 'example',                  # The table to SELECT FROM
                                    'foreign_key_columns' => 'thisobject_id',            # The cols in the foreign table that correspond to Your PKey (can be array if >1 col..)
                                    'foreign_table_pkey'  => 'example_id',               # The primary key of that table                              (can be array if >1 col..)
#                                    'custom_where_clause' => \"\",                             # special condition (other than the FKey column join)
#                                    'order_by_clause'     => '',                             # custom sorting (saves local sorting cost)
                                    ),

               'examples' => array( 'relationship'                    => 'many_to_many',
                                    'include'                         => 'model/Example.class.php',      # A file to require_once(), (should be in include_path)
                                    'class'                           => 'Example',                      # The class name (NOTE: can be THIS class)
                                    'foreign_table'                   => 'example',                      # The final table of the object we will be getting
                                    'join_table'                      => 'example_join',                 # The in-between table that has both pKeys
                                    'pkey_columns_in_join_table'      => 'example_id'                    # (optional) if your PKey is named different in the join table
                                    'foreign_table_pkey'              => 'example_id',                   # The pKey of the final table (note: can be THIS table's pKey)
#                                    'foreign_table_pkey'              => array('join_table_other_example_id' => 'example_id'),                    # The pKey of the final table (note: can be THIS table's pKey)
#                                    'change_status_instead_of_delete' => false,                          # OPTIONAL: Instead of delete, set \"status\" and \"inactive_date\" columns (requires you add these cols)
#                                    'join_table_fixed_values'         => array('peer_type' => 'enemy'),  # OPTIONAL: Alwyas set (and assume to be set) these cols.  Allows for multi-use of the same table
#                                    'order_by_clause'                 => 'name',                         # custom sorting (fields of both the join (jt.) and foreign table (ft.) are valid)
                                    ),
*/"
				);
	}
    fwrite( $fh, "
               );"
			);

	
	if ( $flags['add_get_where'] ) {
		fwrite( $fh, "
    public static function get_where(\$where = null, \$limit_or_only_one = false, \$order_by = null) { return parent::get_where(\$where, \$limit_or_only_one, \$order_by); }"
				);
	}
	if ( $flags['add_get_where_cached'] ) {
		fwrite( $fh, "
    public static function get_where_cached(\$where = null, \$limit_or_only_one = false, \$order_by = null) { return parent::get_where(\$where, \$limit_or_only_one, \$order_by); }"
				);
	}

		fwrite( $fh, "

    ###  Custom Methods

}


"
				 );
}

if ( $verbose ) fwrite(STDERR, "\n\nDone!");


/**
 * Translates a string with underscores into camel case (e.g. first_name -&gt; firstName)
 * @param    string   $str                     String in underscore format
 * @param    bool     $capitalise_first_char   If true, capitalise the first char in $str
 * @return   string                              $str translated into camel caps
 */
function to_camel_case($str, $capitalise_first_char = false) {
  if($capitalise_first_char) {
    $str[0] = strtoupper($str[0]);
  }
  $func = create_function('$c', 'return strtoupper($c[1]);');
  return preg_replace_callback('/_([a-z])/', $func, $str);
}