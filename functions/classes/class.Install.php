<?php

/**
 *	phpIPAM Install class
 */

class Install extends Common_functions {


	/**
	 * to store DB exceptions
	 *
	 * @var mixed
	 * @access public
	 */
	public $exception;

	/**
	 * Database parameters
	 *
	 * @var mixed
	 * @access protected
	 */
	protected $db;

	/**
	 * debugging flag
	 *
	 * (default value: false)
	 *
	 * @var bool
	 * @access public
	 */
	public $debugging = false;

	/**
	 * Result
	 *
	 * @var mixed
	 * @access public
	 */
	public $Result;

	/**
	 * Database
	 *
	 * @var mixed
	 * @access protected
	 */
	protected $Database;

	/**
	 * Database_root - for initial installation
	 *
	 * @var mixed
	 * @access protected
	 */
	protected $Database_root;

	/**
	 * Log
	 *
	 * @var mixed
	 * @access public
	 */
	public $Log;





	/**
	 * __construct function.
	 *
	 * @access public
	 * @param Database_PDO $Database
	 */
	public function __construct (Database_PDO $Database) {
		# initialize Result
		$this->Result = new Result ();
		# initialize object
		$this->Database = $Database;
		# set debugging
		$this->set_debugging ();
		# set debugging
		$this->set_db_params ();
		# Log object
		try { $this->Database->connect(); }
		catch ( Exception $e ) {}
	}









	/**
	 * @install methods
	 * ------------------------------
	 */

	/**
	 * Install database files
	 *
	 * @access public
	 * @param mixed $rootuser
	 * @param mixed $rootpass
	 * @param bool $drop_database (default: false)
	 * @param bool $create_database (default: false)
	 * @param bool $create_grants (default: false)
	 * @param bool $migrate (default: false)
	 * @return void
	 */
	public function install_database ($rootuser, $rootpass, $drop_database = false, $create_database = false, $create_grants = false, $migrate = false) {

		# open new connection
		$this->Database_root = new Database_PDO ($rootuser, $rootpass);

		# set install flag to make sure DB is not trying to be selected via DSN
		$this->Database_root->install = true;

		# drop database if requested
		if($drop_database===true) 	{ $this->drop_database(); }

		# create database if requested
		if($create_database===true) { $this->create_database(); }

		# set permissions!
		if($create_grants===true) 	{ $this->create_grants(); }

	    # reset connection, reset install flag and connect again
		$this->Database_root->resetConn();

		# install database
		if($this->install_database_execute ($migrate) !== false) {
		    # return true, if some errors occured script already died! */
			sleep(1);
			$this->Log = new Logging ($this->Database);
			$this->Log->write( "Database installation", "Database installed successfully. Version ".VERSION.".".REVISION." installed", 1 );
			return true;
		}
	}

	/**
	 * Drop existing database
	 *
	 * @access private
	 * @return void
	 */
	private function drop_database () {
	 	# set query
	    $query = "drop database if exists `". $this->db['name'] ."`;";
		# execute
		try { $this->Database_root->runQuery($query); }
		catch (Exception $e) {	$this->Result->show("danger", $e->getMessage(), true);}
	}

	/**
	 * Create database
	 *
	 * @access private
	 * @return void
	 */
	private function create_database () {
	 	# set query
	    $query = "create database `". $this->db['name'] ."`;";
		# execute
		try { $this->Database_root->runQuery($query); }
		catch (Exception $e) {	$this->Result->show("danger", $e->getMessage(), true);}
	}

	/**
	 * Create user grants
	 *
	 * @access private
	 * @return void
	 */
	private function create_grants () {
		# Set webhost
		$webhost = !empty($this->db['webhost']) ? $this->db['webhost'] : 'localhost';
		# set query
		$query = 'grant ALL on `'. $this->db['name'] .'`.* to \''. $this->db['user'] .'\'@\''. $webhost .'\' identified by "'. $this->db['pass'] .'";';
		# execute
		try { $this->Database_root->runQuery($query); }
		catch (Exception $e) {	$this->Result->show("danger", $e->getMessage(), true);}
	}

	/**
	 * Execute files installation
	 *
	 * @access private
	 * @param $migrate (default: false)
	 * @return void
	 */
	private function install_database_execute ($migrate = false) {
	    # import SCHEMA file queries
	    if($migrate) {
		    $query  = file_get_contents("../../db/MIGRATE.sql");
		}
		else {
		    $query  = file_get_contents("../../db/SCHEMA.sql");
		}

	    # formulate queries
	    $queries = array_filter(explode(";\n", $query));

	    # append version
		$queries[] = "UPDATE `settings` SET `version` = '".VERSION."'";
		$queries[] = "UPDATE `settings` SET `dbversion` = '".DBVERSION."'";
		$queries[] = "UPDATE `settings` SET `dbverified` = 0";

	    # execute
	    foreach($queries as $q) {
		    //length check
		    if (strlen($q)>0) {
				try { $this->Database_root->runQuery($q.";"); }
				catch (Exception $e) {
					//unlock tables
					try { $this->Database_root->runQuery("UNLOCK TABLES;"); }
					catch (Exception $e) {}
					//drop database
					try { $this->Database_root->runQuery("drop database if exists `". $this->db['name'] ."`;"); }
					catch (Exception $e) {
						$this->Result->show("danger", 'Cannot drop database: '.$e->getMessage(), true);
					}
					//print error
					$this->Result->show("danger", "Cannot install sql SCHEMA file: ".$e->getMessage()."<br>query that failed: <pre>$q</pre>", false);
					$this->Result->show("info", "Database dropped", false);

					return false;
				}
			}
	    }
	}










	/**
	 * @check methods
	 * ------------------------------
	 */

	/**
	 * Tries to connect to database
	 *
	 * @access public
	 * @param bool $redirect
	 * @return void
	 */
	public function check_db_connection ($redirect = false) {
		# try to connect
		try { $res = $this->Database->connect(); }
		catch (Exception $e) 	{
			$this->exception = $e->getMessage();
			# redirect ?
			if($redirect == true)  	{ $this->redirect_to_install (); }
			else					{ return false; }
		}
		# ok
		return true;
	}

	/**
	 * Checks if table exists
	 *
	 * @access public
	 * @param mixed $table
	 * @return void
	 */
	public function check_table ($table, $redirect = false) {
		# set query
		$query = "SELECT COUNT(*) AS `cnt` FROM information_schema.tables WHERE table_schema = '".$this->db['name']."' AND table_name = '$table';";
		# try to fetch count
		try { $table = $this->Database->getObjectQuery($query); }
		catch (Exception $e) 	{ if($redirect === true) $this->redirect_to_install ();	else return false; }
		# redirect if it is not existing
		if($table->cnt!=1) 	 	{ if($redirect === true) $this->redirect_to_install ();	else return false; }
		# ok
		return true;
	}

	/**
	 * This function redirects to install page
	 *
	 * @access private
	 * @return void
	 */
	private function redirect_to_install () {
		# redirect to install
		header("Location: ".create_link("install"));
	}

	/**
	 * sets debugging if set in config.php file
	 *
	 * @access private
	 * @return void
	 */
	public function set_debugging () {
		require( dirname(__FILE__) . '/../../config.php' );
		if($debugging==true) { $this->debugging = true; }
	}

	/**
	 * Sets DB parmaeters
	 *
	 * @access private
	 * @return void
	 */
	private function set_db_params () {
		require( dirname(__FILE__) . '/../../config.php' );
		$this->db = $db;
	}









	/**
	 * @postinstallation functions
	 * ------------------------------
	 */

	/**
	 * Post installation settings update.
	 *
	 * @access public
	 * @param mixed $adminpass
	 * @param mixed $siteTitle
	 * @param mixed $siteURL
	 * @return void
	 */
	function postauth_update($adminpass, $siteTitle, $siteURL) {
		# update Admin pass
		$this->postauth_update_admin_pass ($adminpass);
		# update settings
		$this->postauth_update_settings ($siteTitle, $siteURL);
		# ok
		return true;
	}

	/**
	 * Updates admin password after installation
	 *
	 * @access public
	 * @param mixed $adminpass
	 * @return void
	 */
	public function postauth_update_admin_pass ($adminpass) {
		try { $this->Database->updateObject("users", array("password"=>$adminpass, "passChange"=>"No","username"=>"Admin"), "username"); }
		catch (Exception $e) { $this->Result->show("danger", $e->getMessage(), false); }
		return true;
	}

	/**
	 * Updates settings after installation
	 *
	 * @access private
	 * @param mixed $siteTitle
	 * @param mixed $siteURL
	 * @return void
	 */
	private function postauth_update_settings ($siteTitle, $siteURL) {
		try { $this->Database->updateObject("settings", array("siteTitle"=>$siteTitle, "siteURL"=>$siteURL,"id"=>1), "id"); }
		catch (Exception $e) { $this->Result->show("danger", $e->getMessage(), false); }
		return true;
	}










	/**
	 * @upgrade database
	 * -----------------
	 */

	/**
	 * Upgrade database checks and executes.
	 *
	 * @access public
	 * @return void
	 */
	public function upgrade_database () {
		# first check version
		$this->get_settings ();
		# for old version
		if(!isset($this->settings->dbversion)) { $this->settings->dbversion = 0; }

		if($this->settings->version.$this->settings->dbversion == VERSION.DBVERSION) { $this->Result->show("danger", "Database already at latest version", true); }
		else {
			# check db connection
			if($this->check_db_connection(false)===false)  	{ $this->Result->show("danger", "Cannot connect to database", true); }
			# execute
			else {
				return $this->upgrade_database_execute ();
			}
		}
	}

	/**
	 * Execute database upgrade.
	 *
	 * @access private
	 * @return void
	 */
	private function upgrade_database_execute () {
		# set queries
		$subversion_queries = $this->get_upgrade_queries ();
		// create default arrays
		$queries = array();
		// succesfull queries:
		$queries_ok = array();

		// replace CRLF
		$subversion_queries = str_replace("\r\n", "\n", $subversion_queries);
		$queries = array_filter(explode(";", $subversion_queries));

		try {
			# Begin transaction
			$this->Database->beginTransaction();

			# execute all queries
			foreach($queries as $k=>$query) {
				// remove comments
				$query_filtered = trim(preg_replace("/\/\\*.+\*\//", "", $query));
				$query_filtered = trim(preg_replace("/^--.+/", "", $query_filtered));
				$query = trim($query);
				// execute
				$this->Database->runQuery($query_filtered);
				// save ok
				$queries_ok[] = $query;
				// remove old
				unset($queries[$k]);
			}

			$this->Database->runQuery("UPDATE `settings` SET `version` = ?", VERSION);
			$this->Database->runQuery("UPDATE `settings` SET `dbversion` = ?", DBVERSION);
			$this->Database->runQuery("UPDATE `settings` SET `dbverified` = ?", 0);

			# All good, commit changes
			$this->Database->commit();
		}
		catch (Exception $e) {
			# Something went wrong, revert all upgrade changes
			$this->Database->rollBack();

			$this->Log = new Logging ($this->Database);
			# write log
			$this->Log->write( "Database upgrade", $e->getMessage()."<br>query: ".$query, 2 );
			# fail
			print "<h3>Upgrade failed !</h3><hr style='margin:30px;'>";
			$this->Result->show("danger", $e->getMessage()."<hr>Failed query: <pre>".$query.";</pre>", false);

			# print failure
			$this->Result->show("danger", _("Failed to upgrade database!"), false);
			print "<div class='text-right'><a class='btn btn-sm btn-default' href='".create_link('administration', "verify-database")."'>Go to administration and fix</a></div><br><hr><br>";

			if(sizeof($queries_ok)>0)
			$this->Result->show("success", "Succesfull queries: <pre>".implode(";<br>", $queries_ok).";</pre>", false);
			if(sizeof($queries)>0)
			$this->Result->show("warning", "Not executed queries: <pre>".implode(";<br>", $queries).";</pre>", false);

			return false;
		}


		# all good, print it
		usleep(500000);
		$this->Log = new Logging ($this->Database);
		$this->Log->write( "Database upgrade", "Database upgraded from version ".$this->settings->version.".r".$this->settings->dbversion." to version ".VERSION.".r".DBVERSION, 1 );
		return true;
	}

	/**
	 * Fetch all upgrade queries from DB files
	 *
	 * @access public
	 * @return void
	 */
	public function get_upgrade_queries () {
		// fetch settings if not present - for manual instructions
		if (!isset($this->settings->version)) { $this->get_settings (); }

		// queries before 1.3
		$queries_1_3 = $this->settings->version < "1.32" ? $this->get_1_3_upgrade_queries () : "";
		// queries after 1.4
		$queries_1_4 = $this->get_1_4_upgrade_queries ();
		// merge
		$old_queries = $queries_1_3 ."\n\n".$queries_1_4;

		// return
		return $old_queries;
	}

	/**
	 * Upgrade queries for version up to 1.32
	 * @method get_1_3_upgrade_queries
	 * @return string
	 */
	public function get_1_3_upgrade_queries () {
		// save all queries from UPDATE.sql file
		$queries = str_replace("\r\n", "\n", (file_get_contents( dirname(__FILE__) . '/../../db/UPDATE_1.3.sql')));
        // explode and loop to get next version from current
        $delimiter = false;
        foreach (explode("/* VERSION ", $queries) as $k=>$q) {
            $q_version = str_replace(" */", "", array_shift(explode("\n", $q)));

            // if delimiter was found in previous loop
            if ($delimiter!==false) {
                $delimiter = $q_version;
                break;
            }
            // if match with current set pointer to next item - delimiter
            if ($q_version==$this->settings->version) {
                $delimiter = true;
            }
		}
		// remove old queries
		$old_queries = explode("/* VERSION $delimiter */", $queries);
		$old_queries = trim($old_queries[1]);
		// return
		return $old_queries;
	}

	/**
	 * Upgrade queries for version > 1.4
	 * @method get_1_4_upgrade_queries
	 * @return string
	 */
	public function get_1_4_upgrade_queries () {
		// save all queries from UPDATE.sql file
		$queries = str_replace("\r\n", "\n", (file_get_contents( dirname(__FILE__) . '/../../db/UPDATE.sql')));
        // explode and loop to get next version from current
        $delimiter = false;
        foreach (explode("/* VERSION ", $queries) as $k=>$q) {
            $q_version = str_replace(" */", "", array_shift(explode("\n", $q)));

            // if delimiter was found in previous loop
            if ($delimiter!==false) {
                $delimiter = $q_version;
                break;
            }
            // if match with current set pointer to next item - delimiter
            if ($q_version==$this->settings->version.".".$this->settings->dbversion) {
                $delimiter = true;
            }
		}
		// remove old queries
		$old_queries = explode("/* VERSION $delimiter */", $queries);
		// return
		return sizeof($old_queries)>1 ? trim($old_queries[1]) : trim($old_queries[0]);
	}
}
