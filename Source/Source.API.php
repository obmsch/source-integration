<?php

# Copyright (c) 2012 John Reese
# Licensed under the MIT license

/** @noinspection SqlResolve */

require_once( 'MantisSourcePlugin.class.php' );

/**
 * General source control integration API.
 * @author John Reese
 */

# branch mapping strategies
define( 'SOURCE_EXPLICIT',		1 );
define( 'SOURCE_NEAR',			2 );
define( 'SOURCE_FAR',			3 );
define( 'SOURCE_FIRST',			4 );
define( 'SOURCE_LAST',			5 );

function SourceType( $p_type ) {
	$t_types = SourceTypes();

	if ( isset( $t_types[$p_type] ) ) {
		return $t_types[$p_type];
	}

	return $p_type;
}

function SourceTypes() {
	static $s_types = null;

	if ( is_null( $s_types ) ) {
		$s_types = array();

		foreach( SourceVCS::all() as $t_type => $t_vcs ) {
			$s_types[ $t_type ] = $t_vcs->show_type();
		}

		asort( $s_types );
	}

	return $s_types;
}

/**
 * Determine if the Product Matrix integration is enabled, and trigger
 * an error if integration is enabled but the plugin is not running.
 * @param boolean $p_trigger_error Trigger error
 * @return boolean Integration enabled
 */
function Source_PVM( $p_trigger_error=true ) {
	if ( config_get( 'plugin_Source_enable_product_matrix' ) ) {
		if ( plugin_is_loaded( 'ProductMatrix' ) || !$p_trigger_error ) {
			return true;
		} else {
			plugin_error( SourcePlugin::ERROR_PRODUCTMATRIX_NOT_LOADED );
		}
	}
	return false;
}

/**
 * Parse basic bug links from a changeset commit message
 * and return a list of referenced bug IDs.
 * @param string $p_string Changeset commit message
 * @return array Bug IDs
 */
function Source_Parse_Buglinks( $p_string ) {
	static $s_regex1, $s_regex2;

	$t_bugs = array();

	if ( is_null( $s_regex1 ) ) {
		$s_regex1 = config_get( 'plugin_Source_buglink_regex_1' );
		$s_regex2 = config_get( 'plugin_Source_buglink_regex_2' );
	}

	preg_match_all( $s_regex1, $p_string, $t_matches_all );

	foreach( $t_matches_all[0] as $t_substring ) {
		preg_match_all( $s_regex2, $t_substring, $t_matches );
		foreach ( $t_matches[1] as $t_match ) {
			if ( 0 < (int)$t_match ) {
				$t_bugs[$t_match] = true;
			}
		}
	}

	return array_keys( $t_bugs );
}

/**
 * Parse resolved bug fix links from a changeset commit message
 * and return a list of referenced bug IDs.
 * @param string $p_string Changeset commit message
 * @return array Bug IDs
 */
function Source_Parse_Bugfixes( $p_string ) {
	static $s_regex1, $s_regex2;

	$t_bugs = array();

	if ( is_null( $s_regex1 ) ) {
		$s_regex1 = config_get( 'plugin_Source_bugfix_regex_1' );
		$s_regex2 = config_get( 'plugin_Source_bugfix_regex_2' );
	}

	preg_match_all( $s_regex1, $p_string, $t_matches_all );

	foreach( $t_matches_all[0] as $t_substring ) {
		preg_match_all( $s_regex2, $t_substring, $t_matches );
		foreach ( $t_matches[1] as $t_match ) {
			if ( 0 < (int)$t_match ) {
				$t_bugs[$t_match] = true;
			}
		}
	}

	return array_keys( $t_bugs );
}

/**
 * Sets the changeset's user id by looking up email address or name
 * Generic code for both Author and Committer, based on the given properties
 * @param object $p_changeset
 * @param string $p_user_type 'author' or 'committer'
 */
function Source_set_changeset_user( &$p_changeset, $p_user_type ) {
	static $s_vcs_names;
	static $s_names = array();
	static $s_emails = array();

	# Set the fields
	switch( $p_user_type ) {
		case 'committer':
			list( $t_id_prop, $t_name_prop, $t_email_prop ) = explode( ' ', 'committer_id committer committer_email' );
			break;

		case 'author':
		default:
			list( $t_id_prop, $t_name_prop, $t_email_prop ) = explode( ' ', 'user_id author author_email' );
			break;
	}

	# The user's id is already set, nothing to do
	if( $p_changeset->$t_id_prop ) {
		return;
	}

	# cache the vcs username mappings
	if( is_null( $s_vcs_names ) ) {
		$s_vcs_names = SourceUser::load_mappings();
	}

	# Check username associations
	if( isset( $s_vcs_names[ $p_changeset->$t_name_prop ] ) ) {
		$p_changeset->$t_id_prop = $s_vcs_names[ $p_changeset->$t_name_prop ];
		return;
	}

	# Look up the email address if given
	if( $t_email = $p_changeset->$t_email_prop ) {
		if( isset( $s_emails[ $t_email ] ) ) {
			$p_changeset->$t_id_prop = $s_emails[ $t_email ];
			return;

		} else if( false !== ( $t_email_id = user_get_id_by_email( $t_email ) ) ) {
			$s_emails[ $t_email ] = $p_changeset->$t_id_prop = $t_email_id;
			return;
		}
	}

	# Look up the name if the email failed
	if( $t_name = $p_changeset->$t_name_prop ) {
		if( isset( $s_names[ $t_name ] ) ) {
			$p_changeset->$t_id_prop = $s_names[ $t_name ];
			return;

		} else if( false !== ( $t_user_id = user_get_id_by_realname( $t_name ) ) ) {
			$s_names[ $t_name ] = $p_changeset->$t_id_prop = $t_user_id;
			return;

		} else if( false !== ( $t_user_id = user_get_id_by_name( $p_changeset->$t_name_prop ) ) ) {
			$s_names[ $t_name ] = $p_changeset->$t_id_prop = $t_user_id;
			return;
		}
	}
}

/**
 * Determine the user ID for both the author and committer.
 * First checks the email address for a matching user, then
 * checks the name for a matching username or realname.
 * @param object $p_changeset Changeset object
 * @return object updated Changeset object
 */
function Source_Parse_Users( $p_changeset ) {

	# Handle the changeset author
	Source_set_changeset_user( $p_changeset, 'author' );

	# Handle the changeset committer
	Source_set_changeset_user( $p_changeset, 'committer' );

	return $p_changeset;
}

/**
 * Given a set of changeset objects, parse the bug links
 * and save the changes.
 * @param array $p_changesets Changeset objects
 * @param object $p_repo      Repository object
 */
function Source_Process_Changesets( $p_changesets, $p_repo=null ) {
	global $g_cache_current_user_id;

	if ( !is_array( $p_changesets ) ) {
		return;
	}

	if ( is_null( $p_repo ) ) {
		$t_repos = SourceRepo::load_by_changesets( $p_changesets );
	} else {
		$t_repos = array( $p_repo->id => $p_repo );
	}

	$t_resolved_threshold = config_get('bug_resolved_status_threshold');
	$t_fixed_threshold = config_get('bug_resolution_fixed_threshold');
	$t_notfixed_threshold = config_get('bug_resolution_not_fixed_threshold');
	$t_handle_bug_threshold = config_get( 'handle_bug_threshold' );

	# Link author and committer name/email to user accounts
	foreach( $p_changesets as $t_key => $t_changeset ) {
		$p_changesets[ $t_key ] = Source_Parse_Users( $t_changeset );
	}

	# Parse normal bug links, excluding non-existing bugs
	foreach( $p_changesets as $t_changeset ) {
		$t_bugs = Source_Parse_Buglinks( $t_changeset->message );
		foreach( $t_bugs as $t_bug_id ) {
			if( bug_exists( $t_bug_id ) ) {
				$t_changeset->bugs[] = $t_bug_id;
			}
		}
	}

	# Parse fixed bug links
	$t_fixed_bugs = array();

	# Find and associate resolve links with the changeset
	foreach( $p_changesets as $t_changeset ) {
		$t_bugs = Source_Parse_Bugfixes( $t_changeset->message );

		foreach( $t_bugs as $t_key => $t_bug_id ) {
			# Only process existing bugs
			if( bug_exists( $t_bug_id ) ) {
				$t_fixed_bugs[$t_bug_id] = $t_changeset;
			} else {
				unset( $t_bugs[$t_key] );
			}
		}

		# Add the link to the normal set of buglinks
		$t_changeset->bugs = array_unique( array_merge( $t_changeset->bugs, $t_bugs ) );
	}

	# Save changeset data before processing their consequences
	foreach( $p_changesets as $t_changeset ) {
		$t_changeset->repo = $p_repo;
		$t_changeset->save();
	}

	# Precache information for resolved bugs
	bug_cache_array_rows( array_keys( $t_fixed_bugs ) );

	$t_current_user_id = $g_cache_current_user_id;
	$t_enable_resolving = config_get( 'plugin_Source_enable_resolving' );
	$t_enable_message = config_get( 'plugin_Source_enable_message' );
	$t_enable_mapping = config_get( 'plugin_Source_enable_mapping' );

	$t_bugfix_status = config_get( 'plugin_Source_bugfix_status' );
	$t_bugfix_status_pvm = config_get( 'plugin_Source_bugfix_status_pvm' );
	$t_resolution = config_get( 'plugin_Source_bugfix_resolution' );
	$t_handler = config_get( 'plugin_Source_bugfix_handler' );
	$t_message_template = str_replace(
		array( '$1', '$2', '$3', '$4', '$5', '$6' ),
		array( '%1$s', '%2$s', '%3$s', '%4$s', '%5$s', '%6$s' ),
		config_get( 'plugin_Source_bugfix_message' ) );

	$t_mappings = array();

	# Start fixing and/or resolving issues
	foreach( $t_fixed_bugs as $t_bug_id => $t_changeset ) {

		# Determine the Mantis user to associate with the issue referenced in
		# the changeset:
		# - use Author if they can handle the issue
		# - use Committer if not
		# - if Committer can't handle issue either, it will not be resolved.
		# This is used to generate the history entries and set the bug handler
		# if the changeset fixes the issue.
		$t_user_id = null;
		if ( $t_changeset->user_id > 0 ) {
			$t_can_handle_bug = access_has_bug_level( $t_handle_bug_threshold, $t_bug_id, $t_changeset->user_id );
			if( $t_can_handle_bug ) {
				$t_user_id = $t_changeset->user_id;
			}
		}
		$t_handler_id = $t_user_id;
		if( $t_handler_id === null && $t_changeset->committer_id > 0 ) {
			$t_user_id = $t_changeset->committer_id;
			$t_can_handle_bug = access_has_bug_level( $t_handle_bug_threshold, $t_bug_id, $t_user_id );
			if( $t_can_handle_bug ) {
				$t_handler_id = $t_user_id;
			}
		}

		if ( !is_null( $t_user_id ) ) {
			$g_cache_current_user_id = $t_user_id;
		} else if ( !is_null( $t_current_user_id ) ) {
			$g_cache_current_user_id = $t_current_user_id;
		} else {
			$g_cache_current_user_id = 0;
		}

		# generate the branch mappings
		$t_version = '';
		$t_pvm_version_id = 0;
		if ( $t_enable_mapping ) {
			$t_repo_id = $t_changeset->repo_id;

			if ( !isset( $t_mappings[ $t_repo_id ] ) ) {
				$t_mappings[ $t_repo_id ] = SourceMapping::load_by_repo( $t_repo_id );
			}

			if ( isset( $t_mappings[ $t_repo_id ][ $t_changeset->branch ] ) ) {
				$t_mapping = $t_mappings[ $t_repo_id ][ $t_changeset->branch ];
				if ( Source_PVM() ) {
					$t_pvm_version_id = $t_mapping->apply_pvm( $t_bug_id );
				} else {
					$t_version = $t_mapping->apply( $t_bug_id );
				}
			}
		}

		# generate a note message
		if ( $t_enable_message ) {
			$t_message = sprintf( $t_message_template,
				$t_changeset->branch,
				$t_changeset->revision,
				$t_changeset->timestamp->format( DATE_ATOM ),
				$t_changeset->message,
				$t_repos[ $t_changeset->repo_id ]->name,
				$t_changeset->id
			);
		} else {
			$t_message = '';
		}

		$t_bug = bug_get( $t_bug_id );

		# Update the resolution, fixed-in version, and/or add a bugnote
		$t_update = false;

		if ( Source_PVM() ) {
			if ( $t_bugfix_status_pvm > 0 && $t_pvm_version_id > 0 ) {
				/** @noinspection PhpUndefinedClassInspection */
				$t_matrix = new ProductMatrix( $t_bug_id );
				if ( isset( $t_matrix->status[ $t_pvm_version_id ] ) ) {
					/** @noinspection PhpUndefinedFieldInspection */
					$t_matrix->status[ $t_pvm_version_id ] = $t_bugfix_status_pvm;
					/** @noinspection PhpUndefinedMethodInspection */
					$t_matrix->save();
				}
			}

		} elseif( $t_handler && $t_handler_id !== null ) {
			# We only resolve the issue if an authorized handler has been
			# identified; otherwise, it will remain open.

			if ( $t_bugfix_status > 0 && $t_bug->status != $t_bugfix_status ) {
				$t_bug->status = $t_bugfix_status;
				$t_update = true;
			} else if ( $t_enable_resolving && $t_bugfix_status == -1 && $t_bug->status < $t_resolved_threshold ) {
				$t_bug->status = $t_resolved_threshold;
				$t_update = true;
			}

			if( $t_bug->resolution < $t_fixed_threshold || $t_bug->resolution >= $t_notfixed_threshold
				# With default MantisBT settings, 'reopened' is above 'fixed'
				# but below 'not fixed' thresholds, so we need a special case
				# to make sure the resolution is set to 'fixed'.
				|| $t_bug->resolution == REOPENED
			) {
				$t_bug->resolution = $t_resolution;
				$t_update = true;
			}
			if ( is_blank( $t_bug->fixed_in_version ) ) {
				$t_bug->fixed_in_version = $t_version;
				$t_update = true;
			}

			if( $t_bug->handler_id != $t_handler_id ) {
				$t_bug->handler_id = $t_handler_id;
				$t_update = true;
			}
		}

		$t_private = plugin_config_get( 'bugfix_message_view_status' ) == VS_PRIVATE;

		if ( $t_update ) {
			if ( $t_message ) {
				# Add a note without sending mail, since the notification will
				# be sent by the subsequent bug update.
				bugnote_add( $t_bug_id, $t_message, '0:00', $t_private, 0, '', null, false );
			}
			$t_bug->update();

		} else if ( $t_message ) {
			bugnote_add( $t_bug_id, $t_message, '0:00', $t_private );
		}
	}

	# reset the user ID
	$g_cache_current_user_id = $t_current_user_id;

	# Allow other plugins to post-process commit data
	event_signal( 'EVENT_SOURCE_COMMITS', array( $p_changesets ) );
	event_signal( 'EVENT_SOURCE_FIXED', array( $t_fixed_bugs ) );
}

/**
 * Object for handling registration and retrieval of VCS type extension plugins.
 */
class SourceVCS {
	static private $cache = array();

	/**
	 * Initialize the extension cache.
	 */
	static public function init() {
		if ( is_array( self::$cache ) && !empty( self::$cache ) ) {
			return;
		}

		$t_raw_data = event_signal( 'EVENT_SOURCE_INTEGRATION' );
		foreach ( $t_raw_data as $t_plugin => $t_callbacks ) {
			foreach ( $t_callbacks as $t_callback => $t_object ) {
				if ( is_subclass_of( $t_object, 'MantisSourcePlugin' ) &&
					is_string( $t_object->type ) && !is_blank( $t_object->type ) ) {
						$t_type = strtolower($t_object->type);
						self::$cache[ $t_type ] = new SourceVCSWrapper( $t_object );
				}
			}
		}

		ksort( self::$cache );
	}

	/**
	 * Retrieve an extension plugin that can handle the requested repo's VCS type.
	 * If the requested type is not available, the "generic" type will be returned.
	 * @param object $p_repo Repository object
	 * @return MantisSourcePlugin VCS plugin
	 */
	static public function repo( $p_repo ) {
		return self::type( $p_repo->type );
	}

	/**
	 * Retrieve an extension plugin that can handle the requested VCS type.
	 * If the requested type is not available, the "generic" type will be returned.
	 * @param string $p_type VCS type
	 * @return MantisSourcePlugin VCS plugin
	 */
	static public function type( $p_type ) {
		$p_type = strtolower( $p_type );

		if ( isset( self::$cache[ $p_type ] ) ) {
			return self::$cache[ $p_type ];
		} else {
			return self::$cache['generic'];
		}
	}

	/**
	 * Retrieve a list of all registered VCS types.
	 * @return array VCS plugins
	 */
	static public function all() {
		return self::$cache;
	}
}

/**
 * Class for wrapping VCS objects with plugin API calls
 */
class SourceVCSWrapper {
	/**
	 * @var MantisSourcePlugin $object
	 */
	private $object;

	/**
	 * @var string $basename
	 */
	private $basename;

	/**
	 * Build a wrapper around a VCS plugin object.
	 * @param $p_object
	 */
	function __construct( $p_object ) {
		$this->object = $p_object;
		$this->basename = $p_object->basename;
	}

	/**
	 * Wrap method calls to the target object in plugin_push/pop calls.
	 * @param $p_method
	 * @param $p_args
	 * @return mixed
	 */
	function __call( $p_method, $p_args ) {
		plugin_push_current( $this->basename );
		$value = call_user_func_array( array( $this->object, $p_method ), $p_args );
		plugin_pop_current();

		return $value;
	}

	/**
	 * Wrap property reference to target object.
	 * @param $p_name
	 * @return mixed
	 */
	function __get( $p_name ) {
		return $this->object->$p_name;
	}

	/**
	 * Wrap property mutation to target object.
	 * @param $p_name
	 * @param $p_value
	 * @return mixed
	 */
	function __set( $p_name, $p_value ) {
		return $this->object->$p_name = $p_value;
	}
}

/**
 * Abstract source control repository data.
 */
class SourceRepo {
	var $id;
	var $type;
	var $name;
	var $url;
	var $info;
	var $branches;
	var $mappings;

	/**
	 * Build a new Repo object given certain properties.
	 * @param string $p_type Repo type
	 * @param string $p_name Name
	 * @param string $p_url  URL
	 * @param string $p_info Info
	 */
	function __construct( $p_type, $p_name, $p_url='', $p_info='' ) {
		$this->id	= 0;
		$this->type	= $p_type;
		$this->name	= $p_name;
		$this->url	= $p_url;
		if ( is_blank( $p_info ) ) {
			$this->info = array();
		} else {
			$this->info = unserialize( $p_info );
		}
		$this->branches = array();
		$this->mappings = array();
	}

	/**
	 * Create or update repository data.
	 * Creates database row if $this->id is zero, updates an existing row otherwise.
	 */
	function save() {
		if ( is_blank( $this->type ) || is_blank( $this->name ) ) {
			if( is_blank( $this->type ) ) {
				error_parameters( plugin_lang_get( 'type' ) );
			} else {
				error_parameters( plugin_lang_get( 'name' ) );
			}
			trigger_error( ERROR_EMPTY_FIELD, ERROR );
		}

		$t_repo_table = plugin_table( 'repository', 'Source' );

		if ( 0 == $this->id ) { # create
			$t_query = "INSERT INTO $t_repo_table ( type, name, url, info ) VALUES ( " .
				db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )';
			db_query( $t_query, array( $this->type, $this->name, $this->url, serialize($this->info) ) );

			$this->id = db_insert_id( $t_repo_table );
		} else { # update
			$t_query = "UPDATE $t_repo_table SET type=" . db_param() . ', name=' . db_param() .
				', url=' . db_param() . ', info=' . db_param() . ' WHERE id=' . db_param();
			db_query( $t_query, array( $this->type, $this->name, $this->url, serialize($this->info), $this->id ) );
		}

		foreach( $this->mappings as $t_mapping ) {
			$t_mapping->save();
		}
	}

	/**
	 * Load and cache the list of unique branches for the repo's changesets.
	 */
	function load_branches() {
		if ( count( $this->branches ) < 1 ) {
			$t_changeset_table = plugin_table( 'changeset', 'Source' );

			$t_query = "SELECT DISTINCT branch FROM $t_changeset_table WHERE repo_id=" .
				db_param() . ' ORDER BY branch';
			$t_result = db_query( $t_query, array( $this->id ) );

			while( $t_row = db_fetch_array( $t_result ) ) {
				$this->branches[] = $t_row['branch'];
			}
		}

		return $this->branches;
	}

	/**
	 * Load and cache the set of branch mappings for the repository.
	 */
	function load_mappings() {
		if ( count( $this->mappings ) < 1 ) {
			$this->mappings = SourceMapping::load_by_repo( $this->id );
		}

		return $this->mappings;
	}

	/**
	 * Get a list of repository statistics.
	 * @param bool $p_all
	 * @return array Stats
	 */
	function stats( $p_all=true ) {
		$t_stats = array();

		$t_changeset_table = plugin_table( 'changeset', 'Source' );
		$t_file_table = plugin_table( 'file', 'Source' );
		$t_bug_table = plugin_table( 'bug', 'Source' );

		$t_query = "SELECT COUNT(*) FROM $t_changeset_table WHERE repo_id=" . db_param();
		$t_stats['changesets'] = db_result( db_query( $t_query, array( $this->id ) ) );

		if ( $p_all ) {
			# files can be very slow
			if( plugin_config_get( 'show_file_stats' ) ) {
				$t_query = "SELECT COUNT(DISTINCT filename) FROM $t_file_table AS f
						JOIN $t_changeset_table AS c
						ON c.id=f.change_id
						WHERE c.repo_id=" . db_param();
				$t_stats['files'] = db_result( db_query( $t_query, array( $this->id ) ) );
			} else { 
				$t_stats['files'] = -1;
			}

			$t_query = "SELECT COUNT(DISTINCT bug_id) FROM $t_bug_table AS b
						JOIN $t_changeset_table AS c
						ON c.id=b.change_id
						WHERE c.repo_id=" . db_param();
			$t_stats['bugs'] = db_result( db_query( $t_query, array( $this->id ) ) );
		}

		return $t_stats;
	}

	/**
	 * Fetch a new Repo object given an ID.
	 * @param int $p_id Repository ID
	 * @return object Repo object
	 */
	static function load( $p_id ) {
		$t_repo_table = plugin_table( 'repository', 'Source' );

		$t_query = "SELECT * FROM $t_repo_table WHERE id=" . db_param();
		$t_result = db_query( $t_query, array( (int) $p_id ) );

		if ( db_num_rows( $t_result ) < 1 ) {
			error_parameters( $p_id );
			plugin_error( SourcePlugin::ERROR_REPO_MISSING );
		}

		$t_row = db_fetch_array( $t_result );

		$t_repo = new SourceRepo( $t_row['type'], $t_row['name'], $t_row['url'], $t_row['info'] );
		$t_repo->id = $t_row['id'];

		return $t_repo;
	}

	/**
	 * Fetch a new Repo object given a name.
	 * @param string $p_name Repository name
	 * @return SourceRepo Repo object
	 */
	static function load_from_name( $p_name ) {
		$p_name = trim($p_name);
		$t_repo_table = plugin_table( 'repository', 'Source' );

		$t_query = "SELECT * FROM $t_repo_table WHERE name LIKE " . db_param();
		$t_result = db_query( $t_query, array( $p_name ) );

		if ( db_num_rows( $t_result ) < 1 ) {
			error_parameters( $p_name );
			plugin_error( SourcePlugin::ERROR_REPO_MISSING );
		}

		$t_row = db_fetch_array( $t_result );

		$t_repo = new SourceRepo( $t_row['type'], $t_row['name'], $t_row['url'], $t_row['info'] );
		$t_repo->id = $t_row['id'];

		return $t_repo;
	}

	/**
	 * Fetch an array of all Repo objects.
	 * @return array All repo objects.
	 */
	static function load_all() {
		$t_repo_table = plugin_table( 'repository', 'Source' );

		$t_query = "SELECT * FROM $t_repo_table ORDER BY name";
		$t_result = db_query( $t_query );

		$t_repos = array();

		while ( $t_row = db_fetch_array( $t_result ) ) {
			$t_repo = new SourceRepo( $t_row['type'], $t_row['name'], $t_row['url'], $t_row['info'] );
			$t_repo->id = $t_row['id'];

			$t_repos[] = $t_repo;
		}

		return $t_repos;
	}

	/**
	 * Fetch a repository object with the given name.
	 * @param string $p_repo_name
	 * @return null|SourceRepo Repo object, or null if not found
	 */
	static function load_by_name( $p_repo_name ) {
		$t_repo_table = plugin_table( 'repository', 'Source' );

		# Look for a repository with the exact name given
		$t_query = "SELECT * FROM $t_repo_table WHERE name LIKE " . db_param();
		$t_result = db_query( $t_query, array( $p_repo_name ) );

		# If not found, look for a repo containing the name given
		if ( db_num_rows( $t_result ) < 1 ) {
			$t_query = "SELECT * FROM $t_repo_table WHERE name LIKE " . db_param();
			$t_result = db_query( $t_query, array( '%' . $p_repo_name . '%' ) );

			if ( db_num_rows( $t_result ) < 1 ) {
				return null;
			}
		}

		$t_row = db_fetch_array( $t_result );

		$t_repo = new SourceRepo( $t_row['type'], $t_row['name'], $t_row['url'], $t_row['info'] );
		$t_repo->id = $t_row['id'];

		return $t_repo;
	}

	/**
	 * Fetch an array of repository objects that includes all given changesets.
	 * @param array|SourceChangeset $p_changesets Changeset objects
	 * @return array Repository objects
	 */
	static function load_by_changesets( $p_changesets ) {
		if ( !is_array( $p_changesets ) ) {
			$p_changesets = array( $p_changesets );
		}
		elseif ( count( $p_changesets ) < 1 ) {
			return array();
		}

		$t_repo_table = plugin_table( 'repository', 'Source' );

		$t_repos = array();

		foreach ( $p_changesets as $t_changeset ) {
			if ( !isset( $t_repos[$t_changeset->repo_id] ) ) {
				$t_repos[$t_changeset->repo_id] = true;
			}
		}

		$t_list = array();
		$t_param = array();
		foreach ( $t_repos as $t_repo_id => $t_repo ) {
			$t_list[] = db_param();
			$t_param[] = (int)$t_repo_id;
		}
		$t_query = "SELECT * FROM $t_repo_table WHERE id IN ("
			. join( ', ', $t_list )
			. ') ORDER BY name';
		$t_result = db_query( $t_query, $t_param );

		while ( $t_row = db_fetch_array( $t_result ) ) {
			$t_repo = new SourceRepo( $t_row['type'], $t_row['name'], $t_row['url'], $t_row['info'] );
			$t_repo->id = $t_row['id'];

			$t_repos[$t_repo->id] = $t_repo;
		}

		return $t_repos;
	}

	/**
	 * Delete a repository with the given ID.
	 * @param int $p_id Repository ID
	 */
	static function delete( $p_id ) {
		SourceChangeset::delete_by_repo( $p_id );

		$t_repo_table = plugin_table( 'repository', 'Source' );

		$t_query = "DELETE FROM $t_repo_table WHERE id=" . db_param();
		db_query( $t_query, array( (int) $p_id ) );
	}

	/**
	 * Check to see if a repository exists with the given ID.
	 * @param int $p_id Repository ID
	 * @return boolean True if repository exists
	 */
	static function exists( $p_id ) {
		$t_repo_table = plugin_table( 'repository', 'Source' );

		$t_query = "SELECT COUNT(*) FROM $t_repo_table WHERE id=" . db_param();
		$t_result = db_query( $t_query, array( (int) $p_id ) );

		return db_result( $t_result ) > 0;
	}

	static function ensure_exists( $p_id ) {
		if ( !SourceRepo::exists( $p_id ) ) {
			error_parameters( $p_id );
			plugin_error( SourcePlugin::ERROR_REPO_MISSING );
		}
	}
}

/**
 * Abstract source control changeset data.
 */
class SourceChangeset {
	var $id;
	var $repo_id;
	var $user_id;
	var $revision;
	var $parent;
	var $branch;
	var $ported;

	/**
	 * @var DateTimeImmutable $timestamp Commit's time stamp (UTC)
	 */
	var $timestamp;
	var $author;
	var $author_email;
	var $committer;
	var $committer_email;
	var $committer_id;
	var $message;
	var $info;

	/** @var SourceFile[] */
	var $files;
	var $bugs;
	var $__bugs;
	var $repo;

	/**
	 * Build a new changeset object given certain properties.
	 * @param int    $p_repo_id    Repository ID
	 * @param string $p_revision   Changeset revision
	 * @param string $p_branch
	 * @param string $p_timestamp  Commit Timestamp; if no timezone given, assume UTC.
	 * @param string $p_author     Author
	 * @param string $p_message    Commit message
	 * @param int    $p_user_id
	 * @param string $p_parent
	 * @param string $p_ported
	 * @param string $p_author_email
	 */
	function __construct( $p_repo_id, $p_revision, $p_branch='', $p_timestamp='',
		$p_author='', $p_message='', $p_user_id=0, $p_parent='', $p_ported='', $p_author_email='' ) {

		try {
			$t_timestamp = new DateTimeImmutable( $p_timestamp, new DateTimeZone( 'UTC' ) );
		} catch( Exception $e ) {
			trigger_error( ERROR_INVALID_DATE_FORMAT, WARNING );
			$t_timestamp = new DateTimeImmutable( "@0" );
		}

		$this->id				= 0;
		$this->user_id			= $p_user_id;
		$this->repo_id			= $p_repo_id;
		$this->revision			= $p_revision;
		$this->parent			= $p_parent;
		$this->branch			= $p_branch;
		$this->ported			= $p_ported;
		$this->timestamp		= $t_timestamp;
		$this->author			= $p_author;
		$this->author_email		= $p_author_email;
		$this->message			= $p_message;
		$this->info				= '';
		$this->committer		= '';
		$this->committer_email	= '';
		$this->committer_id		= 0;

		$this->files			= array();
		$this->bugs				= array();
		$this->__bugs			= array();
	}

	/**
	 * Create or update changeset data.
	 * Creates database row if $this->id is zero, updates an existing row otherwise.
	 */
	function save() {
		if ( 0 == $this->repo_id ) {
			error_parameters( $this->id );
			plugin_error( SourcePlugin::ERROR_CHANGESET_INVALID_REPO );
		}

		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		# Commit timestamp: can't use DATE_ATOM format to insert datetime,
		# as MySQL < 8.0.19 does not support specifying timezone
		# @see https://dev.mysql.com/doc/refman/8.0/en/datetime.html
		# so we convert the timestamp to UTC first.
		$t_timestamp = $this->timestamp
			->setTimezone( new DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );

		if ( 0 == $this->id ) { # create
			$t_query = "INSERT INTO $t_changeset_table ( repo_id, revision, parent, branch, user_id,
				timestamp, author, message, info, ported, author_email, committer, committer_email, committer_id
				) VALUES ( " .
				db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' .
				db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' .
				db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' .
				db_param() . ', ' . db_param() . ' )';
			db_query( $t_query, array(
				$this->repo_id, $this->revision, $this->parent, $this->branch,
				$this->user_id, $t_timestamp, $this->author, db_mysql_fix_utf8( $this->message ), $this->info,
				$this->ported, $this->author_email, $this->committer, $this->committer_email,
				$this->committer_id ) );

			$this->id = db_insert_id( $t_changeset_table );

			foreach( $this->files as $t_file ) {
				$t_file->change_id = $this->id;
			}

		} else { # update
			$t_query = "UPDATE $t_changeset_table SET repo_id=" . db_param() . ', revision=' . db_param() .
				', parent=' . db_param() . ', branch=' . db_param() . ', user_id=' . db_param() .
				', timestamp=' . db_param() . ', author=' . db_param() . ', message=' . db_param() .
				', info=' . db_param() . ', ported=' . db_param() . ', author_email=' . db_param() .
				', committer=' . db_param() . ', committer_email=' . db_param() . ', committer_id=' . db_param() .
				' WHERE id=' . db_param();
			db_query( $t_query, array(
				$this->repo_id, $this->revision,
				$this->parent, $this->branch, $this->user_id,
				$t_timestamp, $this->author, $this->message,
				$this->info, $this->ported, $this->author_email,
				$this->committer, $this->committer_email,
				$this->committer_id, $this->id ) );
		}

		foreach( $this->files as $t_file ) {
			$t_file->save();
		}

		$this->save_bugs();
	}

	/**
	 * Update changeset relations to affected bugs.
	 * @param int $p_user_id
	 */
	function save_bugs( $p_user_id=null ) {
		$t_bug_table = plugin_table( 'bug', 'Source' );

		$this->bugs = array_unique( $this->bugs );
		$this->__bugs = array_unique( $this->__bugs );

		$t_bugs_added = array_unique( array_diff( $this->bugs, $this->__bugs ) );
		$t_bugs_deleted = array_unique( array_diff( $this->__bugs, $this->bugs ) );

		$this->load_repo();
		$t_vcs = SourceVCS::repo( $this->repo );

		$t_user_id = (int)$p_user_id;
		if ( $t_user_id < 1 ) {
			if ( $this->committer_id > 0 ) {
				$t_user_id = $this->committer_id;
			} else if ( $this->user_id > 0 ) {
				$t_user_id = $this->user_id;
			}
		}

		if ( count( $t_bugs_deleted ) ) {
			$t_bugs_deleted_str = join( ',', $t_bugs_deleted );

			$t_query = "DELETE FROM $t_bug_table WHERE change_id=" . $this->id .
				" AND bug_id IN ( $t_bugs_deleted_str )";
			db_query( $t_query );

			foreach( $t_bugs_deleted as $t_bug_id ) {
				plugin_history_log( $t_bug_id, 'changeset_removed',
					$this->repo->name . ' ' . $t_vcs->show_changeset( $this->repo, $this ),
					'', $t_user_id, 'Source' );
				bug_update_date( $t_bug_id );
			}
		}

		if ( count( $t_bugs_added ) > 0 ) {
			$t_query = "INSERT INTO $t_bug_table ( change_id, bug_id ) VALUES ";

			$t_count = 0;
			$t_params = array();

			foreach( $t_bugs_added as $t_bug_id ) {
				$t_query .= ( $t_count == 0 ? '' : ', ' ) .
					'(' . db_param() . ', ' . db_param() . ')';
				$t_params[] = $this->id;
				$t_params[] = $t_bug_id;
				$t_count++;
			}

			db_query( $t_query, $t_params );

			foreach( $t_bugs_added as $t_bug_id ) {
				plugin_history_log( $t_bug_id, 'changeset_attached', '',
					$this->repo->name . ' ' . $t_vcs->show_changeset( $this->repo, $this ),
					$t_user_id, 'Source' );
				bug_update_date( $t_bug_id );
			}
		}
	}

	/**
	 * Load/cache repo object.
	 */
	function load_repo() {
		if ( is_null( $this->repo ) ) {
			$t_repos = SourceRepo::load_by_changesets( $this );
			$this->repo = array_shift( $t_repos );
		}
	}

	/**
	 * Load all file objects associated with this changeset.
	 */
	function load_files() {
		if ( count( $this->files ) < 1 ) {
			$this->files = SourceFile::load_by_changeset( $this->id );
		}

		return $this->files;
	}

	/**
	 * Load all bug numbers associated with this changeset.
	 */
	function load_bugs() {
		if ( count( $this->bugs ) < 1 ) {
			$t_bug_table = plugin_table( 'bug', 'Source' );

			$t_query = "SELECT bug_id FROM $t_bug_table WHERE change_id=" . db_param();
			$t_result = db_query( $t_query, array( $this->id ) );

			$this->bugs = array();
			$this->__bugs = array();
			while( $t_row = db_fetch_array( $t_result ) ) {
				$this->bugs[] = $t_row['bug_id'];
				$this->__bugs[] = $t_row['bug_id'];
			}
		}

		return $this->bugs;
	}

	/**
	 * Check if a repository's changeset already exists in the database.
	 * @param int    $p_repo_id  Repo ID
	 * @param string $p_revision Revision
	 * @param string $p_branch   Branch
	 * @return boolean True if changeset exists
	 */
	static function exists( $p_repo_id, $p_revision, $p_branch=null ) {
		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		$t_query = "SELECT * FROM $t_changeset_table WHERE repo_id=" . db_param() . '
				AND revision=' . db_param();
		$t_params = array( $p_repo_id, $p_revision );

		if ( !is_null( $p_branch ) ) {
			$t_query .= ' AND branch=' . db_param();
			$t_params[] = $p_branch;
		}

		$t_result = db_query( $t_query, $t_params );
		return db_num_rows( $t_result ) > 0;
	}

	/**
	 * Fetch a new changeset object given an ID.
	 * @param int $p_id Changeset ID
	 * @return mixed Changeset object
	 */
	static function load( $p_id ) {
		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		$t_query = "SELECT * FROM $t_changeset_table WHERE id=" . db_param() . '
				ORDER BY timestamp DESC';
		$t_result = db_query( $t_query, array( $p_id ) );

		if ( db_num_rows( $t_result ) < 1 ) {
			error_parameters( $p_id );
			plugin_error( SourcePlugin::ERROR_CHANGESET_MISSING_ID );
		}

		$t_array = self::from_result( $t_result );
		return array_shift( $t_array );
	}

	/**
	 * Fetch a changeset object given a repository and revision.
	 * @param object $p_repo     Repo object
	 * @param string $p_revision Revision
	 * @return mixed Changeset object
	 */
	static function load_by_revision( $p_repo, $p_revision ) {
		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		$t_query = "SELECT * FROM $t_changeset_table WHERE repo_id=" . db_param() . '
				AND revision=' . db_param() . ' ORDER BY timestamp DESC';
		$t_result = db_query( $t_query, array( $p_repo->id, $p_revision ) );

		if ( db_num_rows( $t_result ) < 1 ) {
			error_parameters( $p_revision, $p_repo->name  );
			plugin_error( SourcePlugin::ERROR_CHANGESET_MISSING_REPO );
		}

		$t_array = self::from_result( $t_result );
		return array_shift( $t_array );
	}

	/**
	 * Fetch an array of changeset objects for a given repository ID.
	 * @param int $p_repo_id Repository ID
	 * @param bool $p_load_files
	 * @param null $p_page
	 * @param int  $p_limit
	 * @return array Changeset objects
	 */
	static function load_by_repo( $p_repo_id, $p_load_files=false, $p_page=null, $p_limit=25  ) {
		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		$t_query = "SELECT * FROM $t_changeset_table WHERE repo_id=" . db_param() . '
				ORDER BY timestamp DESC';
		if ( is_null( $p_page ) ) {
			$t_result = db_query( $t_query, array( $p_repo_id ) );
		} else {
			$t_result = db_query( $t_query, array( $p_repo_id ), $p_limit, ($p_page - 1) * $p_limit );
		}

		return self::from_result( $t_result, $p_load_files );
	}

	/**
	 * Fetch an array of changeset objects for a given bug ID.
	 * @param int  $p_bug_id      Bug ID
	 * @param bool $p_load_files
	 * @return array Changeset objects
	 */
	static function load_by_bug( $p_bug_id, $p_load_files=false ) {
		$t_changeset_table = plugin_table( 'changeset', 'Source' );
		$t_bug_table = plugin_table( 'bug', 'Source' );

		$t_order = strtoupper( config_get( 'history_order' ) ) == 'ASC' ? 'ASC' : 'DESC';
		$t_query = "SELECT c.* FROM $t_changeset_table AS c
		   		JOIN $t_bug_table AS b ON c.id=b.change_id
				WHERE b.bug_id=" . db_param() . "
				ORDER BY c.timestamp $t_order";
		$t_result = db_query( $t_query, array( $p_bug_id ) );

		return self::from_result( $t_result, $p_load_files );
	}

	/**
	 * Return a set of changeset objects from a database result.
	 * Assumes selecting * from changeset_table.
	 * @param IteratorAggregate $p_result Database result
	 * @param bool              $p_load_files
	 * @return array Changeset objects
	 */
	static function from_result( $p_result, $p_load_files=false ) {
		$t_changesets = array();

		while ( $t_row = db_fetch_array( $p_result ) ) {
			$t_changeset = new SourceChangeset( $t_row['repo_id'], $t_row['revision'] );

			try {
				$t_timestamp = new DateTimeImmutable(
					$t_row['timestamp'],
					new DateTimeZone( 'UTC' )
				);
			} catch( Exception $e ) {
				trigger_error( ERROR_INVALID_DATE_FORMAT, WARNING );
				$t_timestamp = new DateTimeImmutable( "@0" );
			}

			$t_changeset->id = $t_row['id'];
			$t_changeset->parent = $t_row['parent'];
			$t_changeset->branch = $t_row['branch'];
			$t_changeset->timestamp = $t_timestamp;
			$t_changeset->user_id = $t_row['user_id'];
			$t_changeset->author = $t_row['author'];
			$t_changeset->author_email = $t_row['author_email'];
			$t_changeset->message = $t_row['message'];
			$t_changeset->info = $t_row['info'];
			$t_changeset->ported = $t_row['ported'];
			$t_changeset->committer = $t_row['committer'];
			$t_changeset->committer_email = $t_row['committer_email'];
			$t_changeset->committer_id = $t_row['committer_id'];

			if ( $p_load_files ) {
				$t_changeset->load_files();
			}

			$t_changesets[ $t_changeset->id ] = $t_changeset;
		}

		return $t_changesets;
	}

	/**
	 * Delete all changesets for a given repository ID.
	 * @param int $p_repo_id Repository ID
	 */
	static function delete_by_repo( $p_repo_id ) {
		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		# first drop any files for the repository's changesets
		SourceFile::delete_by_repo( $p_repo_id );

		$t_query = "DELETE FROM $t_changeset_table WHERE repo_id=" . db_param();
		db_query( $t_query, array( $p_repo_id ) );
	}

	/**
	 * Get the changeset's timestamp in the user's timezone.
	 *
	 * @param string $p_format Date format, defaults to $g_normal_date_format.
	 *
	 * @return string
	 */
	public function getLocalTimestamp( $p_format = null )
	{
		if( !$p_format ) {
			$p_format = config_get( 'normal_date_format' );
		}

		return date( $p_format, $this->timestamp->getTimestamp() );
	}

}

/**
 * Abstract source control file data.
 */
class SourceFile {
	/**
	 * Action constants
	 */
	const ADDED = 'add';
	const MODIFIED = 'mod';
	const RENAMED = 'mv';
	const DELETED = 'rm';
	const UNKNOWN = '??';
	# These are used by SourceHgWeb - not sure what they actually mean
	const BINARY = 'bin';
	const NA = 'n/a';

	/**
	 * String used to separate old and new filename in RENAMED operations.
	 */
	const RENAMED_SEPARATOR = ' → ';

	var $id;
	var $change_id;
	var $revision;
	var $action;
	var $filename;

	function __construct( $p_change_id, $p_revision, $p_filename, $p_action='' ) {
		$this->id			= 0;
		$this->change_id	= $p_change_id;
		$this->revision		= $p_revision;
		$this->action		= $p_action;
		$this->filename		= $p_filename;
	}

	/**
	 * Return the File's name.
	 *
	 * In rename operations, filename is stored as 'old → new' so we need to
	 * conditionally split the string and return only the new filename to
	 * avoid problems when it's used e.g. in URLs.
	 *
	 * @return string
	 */
	public function getFilename() {
		if ($this->action == SourceFile::RENAMED) {
			$t_split = explode(SourceFile::RENAMED_SEPARATOR, $this->filename);
			return isset( $t_split[1] ) ? $t_split[1] : $t_split[0];
		}
		return $this->filename;
	}

	function save() {
		if ( 0 == $this->change_id ) {
			error_parameters( $this->id );
			plugin_error( SourcePlugin::ERROR_FILE_INVALID_CHANGESET );
		}

		$t_file_table = plugin_table( 'file', 'Source' );

		if ( 0 == $this->id ) { # create
			$t_query = "INSERT INTO $t_file_table ( change_id, revision, action, filename ) VALUES ( " .
				db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )';
			db_query( $t_query, array( $this->change_id, $this->revision, $this->action, $this->filename ) );

			$this->id = db_insert_id( $t_file_table );
		} else { # update
			$t_query = "UPDATE $t_file_table SET change_id=" . db_param() . ', revision=' . db_param() .
				', action=' . db_param() . ', filename=' . db_param() . ' WHERE id=' . db_param();
			db_query( $t_query, array( $this->change_id, $this->revision, $this->action, $this->filename, $this->id ) );
		}
	}

	static function load( $p_id ) {
		$t_file_table = plugin_table( 'file', 'Source' );

		$t_query = "SELECT * FROM $t_file_table WHERE id=" . db_param();
		$t_result = db_query( $t_query, array( $p_id ) );

		if ( db_num_rows( $t_result ) < 1 ) {
			error_parameters( $p_id );
			plugin_error( SourcePlugin::ERROR_FILE_MISSING );
		}

		$t_row = db_fetch_array( $t_result );
		$t_file = new SourceFile( $t_row['change_id'], $t_row['revision'], $t_row['filename'], $t_row['action'] );
		$t_file->id = $t_row['id'];

		return $t_file;
	}

	static function load_by_changeset( $p_change_id ) {
		$t_file_table = plugin_table( 'file', 'Source' );

		$t_query = "SELECT * FROM $t_file_table WHERE change_id=" . db_param();
		$t_result = db_query( $t_query, array( $p_change_id ) );

		$t_files = array();

		while ( $t_row = db_fetch_array( $t_result ) ) {
			$t_file = new SourceFile( $t_row['change_id'], $t_row['revision'], $t_row['filename'], $t_row['action'] );
			$t_file->id = $t_row['id'];
			$t_files[] = $t_file;
		}

		return $t_files;
	}

	static function delete_by_changeset( $p_change_id ) {
		$t_file_table = plugin_table( 'file', 'Source' );

		$t_query = "DELETE FROM $t_file_table WHERE change_id=" . db_param();
		db_query( $t_query, array( $p_change_id ) );
	}

	/**
	 * Delete all file objects from the database for a given repository.
	 * @param int $p_repo_id Repository ID
	 */
	static function delete_by_repo( $p_repo_id ) {
		$t_file_table = plugin_table( 'file', 'Source' );
		$t_changeset_table = plugin_table( 'changeset', 'Source' );

		$t_query = "DELETE FROM $t_file_table WHERE change_id IN ( SELECT id FROM $t_changeset_table WHERE repo_id=" . db_param() . ')';
		db_query( $t_query, array( $p_repo_id ) );
	}
}

/**
 * Class for handling branch version mappings on a repository.
 */
class SourceMapping {
	var $_new = true;
	var $repo_id;
	var $branch;
	var $type;

	var $version;
	var $regex;
	var $pvm_version_id;

	/**
	 * Initialize a mapping object.
	 * @param int    $p_repo_id
	 * @param string $p_branch
	 * @param int    $p_type
	 * @param string $p_version
	 * @param string $p_regex
	 * @param int    $p_pvm_version_id
	 */
	function __construct( $p_repo_id, $p_branch, $p_type, $p_version='', $p_regex='', $p_pvm_version_id=0 ) {
		$this->repo_id = $p_repo_id;
		$this->branch = $p_branch;
		$this->type = $p_type;
		$this->version = $p_version;
		$this->regex = $p_regex;
		$this->pvm_version_id = $p_pvm_version_id;
	}

	/**
	 * Save the given mapping object to the database.
	 */
	function save() {
		$t_branch_table = plugin_table( 'branch' );

		if ( $this->_new ) {
			$t_query = "INSERT INTO $t_branch_table ( repo_id, branch, type, version, regex, pvm_version_id ) VALUES (" .
				db_param() . ', ' .db_param() . ', ' .db_param() . ', ' .db_param() . ', ' .    db_param() . ', ' .    db_param() . ')';
			db_query( $t_query, array( $this->repo_id, $this->branch, $this->type, $this->version, $this->regex, $this->pvm_version_id ) );

		} else {
			$t_query = "UPDATE $t_branch_table SET branch=" . db_param() . ', type=' . db_param() . ', version=' . db_param() .
				', regex=' . db_param() . ', pvm_version_id=' . db_param() . ' WHERE repo_id=' . db_param() . ' AND branch=' . db_param();
			db_query( $t_query, array( $this->branch, $this->type, $this->version,
				$this->regex, $this->pvm_version_id, $this->repo_id, $this->branch ) );
		}
	}

	/**
	 * Delete a branch mapping.
	 */
	function delete() {
		$t_branch_table = plugin_table( 'branch' );

		if ( !$this->_new ) {
			$t_query = "DELETE FROM $t_branch_table WHERE repo_id=" . db_param() . ' AND branch=' . db_param();
			db_query( $t_query, array( $this->repo_id, $this->branch ) );

			$this->_new = true;
		}
	}

	/**
	 * Load a group of mapping objects for a given repository.
	 * @param int $p_repo_id Repository object
	 * @return array Mapping objects
	 */
	static function load_by_repo( $p_repo_id ) {
		$t_branch_table = plugin_table( 'branch' );

		$t_query = "SELECT * FROM $t_branch_table WHERE repo_id=" . db_param() . ' ORDER BY branch';
		$t_result = db_query( $t_query, array( $p_repo_id ) );

		$t_mappings = array();

		while( $t_row = db_fetch_array( $t_result ) ) {
			$t_mapping = new SourceMapping( $t_row['repo_id'], $t_row['branch'], $t_row['type'], $t_row['version'], $t_row['regex'], $t_row['pvm_version_id'] );
			$t_mapping->_new = false;

			$t_mappings[$t_mapping->branch] = $t_mapping;
		}

		return $t_mappings;
	}

	/**
	 * Given a bug ID, apply the appropriate branch mapping algorithm
	 * to find and return the appropriate version ID.
	 * @param int $p_bug_id Bug ID
	 * @return int Version ID
	 */
	function apply( $p_bug_id ) {
		static $s_versions = array();
		static $s_versions_sorted = array();

		# if it's explicit, return the version_id before doing anything else
		if ( $this->type == SOURCE_EXPLICIT ) {
			return $this->version;
		}

		# cache project/version sets, and the appropriate sorting
		$t_project_id = bug_get_field( $p_bug_id, 'project_id' );
		if ( !isset( $s_versions[ $t_project_id ] ) ) {
			$s_versions[ $t_project_id ] = version_get_all_rows( $t_project_id, false );
		}

		# handle empty version sets
		if ( count( $s_versions[ $t_project_id ] ) < 1 ) {
			return '';
		}

		# cache the version set based on the current algorithm
		if ( !isset( $s_versions_sorted[ $t_project_id ][ $this->type ] ) ) {
			$s_versions_sorted[ $t_project_id ][ $this->type ] = $s_versions[ $t_project_id ];

			switch( $this->type ) {
				case SOURCE_NEAR:
					usort( $s_versions_sorted[ $t_project_id ][ $this->type ], array( 'SourceMapping', 'cmp_near' ) );
					break;
				case SOURCE_FAR:
					usort( $s_versions_sorted[ $t_project_id ][ $this->type ], array( 'SourceMapping', 'cmp_far' ) );
					break;
				case SOURCE_FIRST:
					usort( $s_versions_sorted[ $t_project_id ][ $this->type ], array( 'SourceMapping', 'cmp_first' ) );
					break;
				case SOURCE_LAST:
					usort( $s_versions_sorted[ $t_project_id ][ $this->type ], array( 'SourceMapping', 'cmp_last' ) );
					break;
			}
		}

		# pull the appropriate versions set from the cache
		$t_versions = $s_versions_sorted[ $t_project_id ][ $this->type ];

		# handle non-regex mappings
		if ( is_blank( $this->regex ) ) {
			return $t_versions[0]['version'];
		}

		# handle regex mappings
		foreach( $t_versions as $t_version ) {
			if ( preg_match( $this->regex, $t_version['version'] ) ) {
				return $t_version['version'];
			}
		}

		# no version matches the regex
		return '';
	}

	/**
	 * Given a bug ID, apply the appropriate branch mapping algorithm
	 * to find and return the appropriate product matrix version ID.
	 * @param int $p_bug_id Bug ID
	 * @return int Product version ID
	 *
	 * @noinspection PhpUnusedParameterInspection
	 */
	function apply_pvm( $p_bug_id ) {
		# if it's explicit, return the version_id before doing anything else
		if ( $this->type == SOURCE_EXPLICIT ) {
			return $this->pvm_version_id;
		}

		# no version matches the regex
		return 0;
	}

	function cmp_near( $a, $b ) {
		return strcmp( $a['date_order'], $b['date_order'] );
	}
	function cmp_far( $a, $b ) {
		return strcmp( $b['date_order'], $a['date_order'] );
	}
	function cmp_first( $a, $b ) {
		return version_compare( $a['version'], $b['version'] );
	}
	function cmp_last( $a, $b ) {
		return version_compare( $b['version'], $a['version'] );
	}
}

/**
 * Object for handling VCS username associations.
 */
class SourceUser {
	var $new = true;

	var $user_id;
	var $username;

	function __construct( $p_user_id, $p_username='' ) {
		$this->user_id = $p_user_id;
		$this->username = $p_username;
	}

	/**
	 * Load a user object from the database for a given user ID, or generate
	 * a new object if the database entry does not exist.
	 * @param int $p_user_id User ID
	 * @return object User object
	 */
	static function load( $p_user_id ) {
		$t_user_table = plugin_table( 'user', 'Source' );

		$t_query = "SELECT * FROM $t_user_table WHERE user_id=" . db_param();
		$t_result = db_query( $t_query, array( $p_user_id ) );

		if ( db_num_rows( $t_result ) > 0 ) {
			$t_row = db_fetch_array( $t_result );

			$t_user = new SourceUser( $t_row['user_id'], $t_row['username'] );
			$t_user->new = false;

		} else {
			$t_user = new SourceUser( $p_user_id );
		}

		return $t_user;
	}

	/**
	 * Load all user objects from the database and create an array indexed by
	 * username, pointing to user IDs.
	 * @return array Username mappings
	 */
	static function load_mappings() {
		$t_user_table = plugin_table( 'user', 'Source' );

		$t_query = "SELECT * FROM $t_user_table";
		$t_result = db_query( $t_query );

		$t_usernames = array();
		while( $t_row = db_fetch_array( $t_result ) ) {
			$t_usernames[ $t_row['username'] ] = $t_row['user_id'];
		}

		return $t_usernames;
	}

	/**
	 * Persist a user object to the database.  If the user object contains a blank
	 * username, then delete any existing data from the database to minimize storage.
	 */
	function save() {
		$t_user_table = plugin_table( 'user', 'Source' );

		# handle new objects
		if ( $this->new ) {
			if ( is_blank( $this->username ) ) { # do nothing
				return;

			} else { # insert new entry
				$t_query = "INSERT INTO $t_user_table ( user_id, username ) VALUES (" .
					db_param() . ', ' . db_param() . ')';
				db_query( $t_query, array( $this->user_id, $this->username ) );

				$this->new = false;
			}

		# handle loaded objects
		} else {
			if ( is_blank( $this->username ) ) { # delete existing entry
				$t_query = "DELETE FROM $t_user_table WHERE user_id=" . db_param();
				db_query( $t_query, array( $this->user_id ) );

			} else { # update existing entry
				$t_query = "UPDATE $t_user_table SET username=" . db_param() .
					' WHERE user_id=' . db_param();
				db_query( $t_query, array( $this->username, $this->user_id ) );
			}
		}
	}
}
