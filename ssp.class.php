<?php

/*
 * Helper functions for building a DataTables server-side processing SQL query
 *
 * The static functions in this class are just helper functions to help build
 * the SQL used in the DataTables demo server-side processing scripts. These
 * functions obviously do not represent all that can be done with server-side
 * processing, they are intentionally simple to show how it works. More complex
 * server-side processing operations will likely require a custom script.
 *
 * See http://datatables.net/usage/server-side for full details on the server-
 * side processing requirements of DataTables.
 *
 * @license MIT - http://datatables.net/license_mit
 */


// REMOVE THIS BLOCK - used for DataTables test environment only!
$file = $_SERVER['DOCUMENT_ROOT'] . '/datatables/mysql.php';
if (is_file($file)) {
	include($file);
}


class SSP
{
	/**
	 * Create the data output array for the DataTables rows
	 *
	 *  @param  array $columns Column information array
	 *  @param  array $data    Data from the SQL get
	 *  @return array          Formatted data in a row based format
	 */

	public static $join = '';
	static function data_output($columns, $data)
	{
		$out = array();

		for ($i = 0, $ien = count($data); $i < $ien; $i++) {
			$row = array();

			for ($j = 0, $jen = count($columns); $j < $jen; $j++) {
				$column = $columns[$j];

				// Is there a formatter?

				$colName = (isset($columns[$j]['output'])) ? $columns[$j]['output'] : $columns[$j]['db'];

				// print_r($column['formatter']($data[$i][$colName], $data[$i]));
				// exit;
				if (isset($column['formatter'])) {
					$row[$column['dt']] = $column['formatter']($data[$i][$colName], $data[$i]);
				} else {
					$row[$column['dt']] = $data[$i][$colName];
				}
			}

			$out[] = $row;
		}

		return $out;
	}


	/**
	 * Paging
	 *
	 * Construct the LIMIT clause for server-side processing SQL query
	 *
	 *  @param  array $request Data sent to server by DataTables
	 *  @param  array $columns Column information array
	 *  @return string SQL limit clause
	 */
	static function limit($request, $columns)
	{
		$limit = '';

		if (isset($request['start']) && $request['length'] != -1) {
			$limit = "LIMIT " . intval($request['start']) . ", " . intval($request['length']);
		}

		return $limit;
	}


	/**
	 * Ordering
	 *
	 * Construct the ORDER BY clause for server-side processing SQL query
	 *
	 *  @param  array $request Data sent to server by DataTables
	 *  @param  array $columns Column information array
	 *  @return string SQL order by clause
	 */
	static function order($request, $columns)
	{
		$order = '';

		if (isset($request['order']) && count($request['order'])) {
			$orderBy = array();
			$dtColumns = self::pluck($columns, 'dt');

			for ($i = 0, $ien = count($request['order']); $i < $ien; $i++) {
				// Convert the column index into the column data property
				$columnIdx = intval($request['order'][$i]['column']);
				$requestColumn = $request['columns'][$columnIdx];

				$columnIdx = array_search($requestColumn['data'], $dtColumns);
				$column = $columns[$columnIdx];

				if ($requestColumn['orderable'] == 'true') {
					$dir = $request['order'][$i]['dir'] === 'asc' ?
						'ASC' :
						'DESC';
					$colName = (isset($column['output'])) ? $column['output'] : $column['db'];
					$orderBy[] = '' . $colName . ' ' . $dir;
				}
			}

			$order = 'ORDER BY ' . implode(', ', $orderBy);
		}

		return $order;
	}


	/**
	 * Searching / Filtering
	 *
	 * Construct the WHERE clause for server-side processing SQL query.
	 *
	 * NOTE this does not match the built-in DataTables filtering which does it
	 * word by word on any field. It's possible to do here performance on large
	 * databases would be very poor
	 *
	 *  @param  array $request Data sent to server by DataTables
	 *  @param  array $columns Column information array
	 *  @param  array $bindings Array of values for PDO bindings, used in the
	 *    sql_exec() function
	 *  @return string SQL where clause
	 */
	static function filter($request, $columns, &$bindings, $default = false)
	{
		$globalSearch = array();
		$columnSearch = array();
		$dtColumns = self::pluck($columns, 'dt');

		if (isset($request['search']) && $request['search']['value'] != '') {
			$str = $request['search']['value'];

			for ($i = 0, $ien = count($request['columns']); $i < $ien; $i++) {
				$requestColumn = $request['columns'][$i];
				$columnIdx = array_search($requestColumn['data'], $dtColumns);
				$column = $columns[$columnIdx];

				$colName = (isset($column['search'])) ? $column['search'] : $column['db'];
				if ($requestColumn['searchable'] == 'true') {
					$binding = self::bind($bindings, '%' . $str . '%', PDO::PARAM_STR);
					$globalSearch[] = "`" . $colName . "` LIKE " . $binding;
				}
			}
		}

		// Individual column filtering
		for ($i = 0, $ien = count($request['columns']); $i < $ien; $i++) {
			$requestColumn = $request['columns'][$i];
			$columnIdx = array_search($requestColumn['data'], $dtColumns);
			$column = $columns[$columnIdx];

			$str = $requestColumn['search']['value'];

			if (
				$requestColumn['searchable'] == 'true' &&
				$str != ''
			) {
				$binding = self::bind($bindings, '%' . $str . '%', PDO::PARAM_STR);
				$colName = (isset($column['search'])) ? $column['search'] : $column['db'];
				$columnSearch[] = "" . $colName . " LIKE " . $binding;
			}
		}

		// Combine the filters into a single string
		$where = '';

		if (count($globalSearch)) {
			$where = '(' . implode(' OR ', $globalSearch) . ')';
		}

		if (count($columnSearch)) {
			$where = $where === '' ?
				implode(' AND ', $columnSearch) :
				$where . ' AND ' . implode(' AND ', $columnSearch);
		}

		if ($default !== false) {
			if ($where !== '')
				$where = $default . ' AND ' . $where;
			else
				$where = $default;
		}
		if ($where !== '') {
			$where = 'WHERE ' . $where;
		}

		return $where;
	}


	/**
	 * Perform the SQL queries needed for an server-side processing requested,
	 * utilising the helper functions of this class, limit(), order() and
	 * filter() among others. The returned array is ready to be encoded as JSON
	 * in response to an SSP request, or can be modified if needed before
	 * sending back to the client.
	 *
	 *  @param  array $request Data sent to server by DataTables
	 *  @param  array $sql_details SQL connection details - see sql_connect()
	 *  @param  string $table SQL table to query
	 *  @param  string $primaryKey Primary key of the table
	 *  @param  array $columns Column information array
	 *  @param  string $default Default Where clause string 
	 *  @return array          Server-side processing response array
	 */

	static function getColumnName($column): array
	{
		$column_names = [];

		foreach ($column as $row) {
			$column_names[] = $row['db'];
		}

		return $column_names;
	}
	static function simple($request, $sql_details, $table, $primaryKey, $columns, $default = false)
	{
		$bindings = array();
		$db = self::sql_connect($sql_details);

		// Build the SQL query string from the request
		$limit = self::limit($request, $columns);
		$order = self::order($request, $columns);
		$where = self::filter($request, $columns, $bindings, $default);
		$join = self::$join;

		// Main query to actually get the data
		$data = self::sql_exec(
			$db,
			$bindings,
			"SELECT " . implode(", ", self::getColumnName($columns)) . "
			 FROM `$table`
			 $join
			 $where
			 $order
			 $limit"
		);

		// Data set length after filtering
		$resFilterLength = self::sql_exec(
			$db,
			"SELECT FOUND_ROWS()"
		);
		$recordsTotal = $resFilterLength[0][0];

		// Total data set length
		$resTotalLength = self::getCount(
			$db,
			$bindings,
			"SELECT " . implode(", ", self::getColumnName($columns)) . "
			 FROM `$table`
			 $join
			 $where"
		);
		$recordsFiltered = $resTotalLength;


		/*
		 * Output
		 */
		return array(
			"draw"            => intval($request['draw']),
			"recordsTotal"    => intval($recordsTotal),
			"recordsFiltered" => intval($recordsFiltered),
			"data"            => self::data_output($columns, $data)
		);
	}


	/**
	 * Connect to the database
	 *
	 * @param  array $sql_details SQL server connection details array, with the
	 *   properties:
	 *     * host - host name
	 *     * db   - database name
	 *     * user - user name
	 *     * pass - user password
	 * @return resource Database connection handle
	 */
	static function sql_connect($sql_details)
	{
		try {
			$db = @new PDO(
				"mysql:host={$sql_details['host']};dbname={$sql_details['db']}",
				$sql_details['user'],
				$sql_details['pass'],
				array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
			);
		} catch (PDOException $e) {
			self::fatal(
				"An error occurred while connecting to the database. " .
					"The error reported by the server was: " . $e->getMessage()
			);
		}

		return $db;
	}


	/**
	 * Execute an SQL query on the database
	 *
	 * @param  resource $db  Database handler
	 * @param  array    $bindings Array of PDO binding values from bind() to be
	 *   used for safely escaping strings. Note that this can be given as the
	 *   SQL query string if no bindings are required.
	 * @param  string   $sql SQL query to execute.
	 * @return array         Result from the query (all rows)
	 */
	static function sql_exec($db, $bindings, $sql = null)
	{
		// Argument shifting
		if ($sql === null) {
			$sql = $bindings;
		}

		$stmt = $db->prepare($sql);
		//echo $sql;

		// Bind parameters
		if (is_array($bindings)) {
			for ($i = 0, $ien = count($bindings); $i < $ien; $i++) {
				$binding = $bindings[$i];
				$stmt->bindValue($binding['key'], $binding['val'], $binding['type']);
			}
		}

		// Execute
		try {
			$stmt->execute();
		} catch (PDOException $e) {
			self::fatal("An SQL error occurred: " . $e->getMessage());
		}

		// Return all
		return $stmt->fetchAll();
	}
	static function getCount($db, $bindings, $sql = null)
	{
		// Argument shifting
		if ($sql === null) {
			$sql = $bindings;
		}

		$stmt = $db->prepare($sql);
		//echo $sql;

		// Bind parameters
		if (is_array($bindings)) {
			for ($i = 0, $ien = count($bindings); $i < $ien; $i++) {
				$binding = $bindings[$i];
				$stmt->bindValue($binding['key'], $binding['val'], $binding['type']);
			}
		}

		// Execute
		try {
			$stmt->execute();
		} catch (PDOException $e) {
			self::fatal("An SQL error occurred: " . $e->getMessage());
		}

		// Return all
		return $stmt->rowCount();
	}

	/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
	 * Internal methods
	 */

	/**
	 * Throw a fatal error.
	 *
	 * This writes out an error message in a JSON string which DataTables will
	 * see and show to the user in the browser.
	 *
	 * @param  string $msg Message to send to the client
	 */
	static function fatal($msg)
	{
		echo json_encode(array(
			"error" => $msg
		));

		exit(0);
	}

	/**
	 * Create a PDO binding key which can be used for escaping variables safely
	 * when executing a query with sql_exec()
	 *
	 * @param  array &$a    Array of bindings
	 * @param  *      $val  Value to bind
	 * @param  int    $type PDO field type
	 * @return string       Bound key to be used in the SQL where this parameter
	 *   would be used.
	 */
	static function bind(&$a, $val, $type)
	{
		$key = ':binding_' . count($a);

		$a[] = array(
			'key' => $key,
			'val' => $val,
			'type' => $type
		);

		return $key;
	}


	/**
	 * Pull a particular property from each assoc. array in a numeric array, 
	 * returning and array of the property values from each item.
	 *
	 *  @param  array  $a    Array to get data from
	 *  @param  string $prop Property to read
	 *  @return array        Array of property values
	 */
	static function pluck($a, $prop)
	{
		$out = array();

		for ($i = 0, $len = count($a); $i < $len; $i++) {
			$out[] = $a[$i][$prop];
		}

		return $out;
	}
}
