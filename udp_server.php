<?php

	set_time_limit( 0 );
	ob_implicit_flush();
	
	$mysql_user = 'root';				// 数据库访问用户名
	$mysql_pass = 'blue';				// 数据库访问密钥
	$port = 1024;
	
	$A_t = array();				// 接收到指令的时间，UTC时间
	$A_gid = array();			
	$A_ip = array();
	$A_port = array();
	$A_id = array();
	
START:
	$socket = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );
	if( $socket===false ) {
		echo "socket_create() failed:reason:" . socket_strerror( socket_last_error() ) . "\n";
		exit;
	}

	$rval = socket_get_option($socket, SOL_SOCKET, SO_REUSEADDR);
	if( $rval===false )
		echo 'Unable to get socket option: '. socket_strerror(socket_last_error()).PHP_EOL;
	elseif( $rval!==0 )
		echo 'SO_REUSEADDR is set on socket !'.PHP_EOL;
		
	socket_set_option( $socket, SOL_SOCKET, SO_RCVTIMEO, array("sec"=>4, "usec"=>0 ) );

	$ok = socket_bind( $socket, '0.0.0.0', $port );
	if( $ok===false ) {
		echo "false  \r\n";
		echo "socket_bind() failed:reason:" . socket_strerror( socket_last_error( $socket ) )."\r\n";
		exit;
	}
/*
	socket_getsockname ( $socket, $A, $P );
	echo get_local_ip().'     '.$P.'         '.time()."\n";
	socket_close( $socket );
	exit;
*/	
	echo "The udp server is running!\n";
	
	while( true ) {
		//echo "\n \$A_t    ".count($A_t)."\n";
		
		foreach( $A_t as $k => $v ) {
			if( (time()-$v)>30 ) {
				unset( $A_gid[$k] );
				unset( $A_id[$k] );
				unset( $A_t[$k] );
				unset( $A_ip[$k] );
				unset( $A_port[$k] );
			}
		}
			
		$r = array( $socket );

		$num = socket_select( $r, $w=NULL, $e=NULL, 16 );
		if( $num===false ) {
			echo "socket_select() failed, reason: ".socket_strerror(socket_last_error())."\n";
			socket_close( $socket );
			sleep( 20 );
			goto START;
		}
		elseif( $num>0 ) {
				socket_recvfrom( $socket, $buf, 1000, 0, $to_ip, $to_port );
				if( strlen($buf)>1 ) {
					
					echo "op_res---".$buf."\n";
					
					$str_array = str_split( $buf );
					$buf = substr( $buf, 1 );
					
					switch( $str_array[0] ) {
						case 'D':					// 解析数据
							parse_data( $buf );
							break;
							
						case 'I':					// 解析设备ip、port
							$gid = parse_I( $buf );
							socket_getsockname ( $socket, $A, $P );
							$A = get_local_ip();
							save_local_ip_port( $gid, $A, $P );
							save_remote_ip_port( $gid, $to_ip, $to_port );
							break;
							
						case 'S':					// 客户发送指令
							parse_S( $buf, $gid, $cmd );
							$r_ip = '';
							$r_port = '';
							get_remote_ip_port( $gid, $r_ip, $r_port );
							if( empty($r_ip) ) {
								$msg = 'FAIL';
								socket_sendto( $socket, $msg, strlen($msg), 0, $to_ip, $to_port ); 						
								break;
							}
							
							array_push( $A_t, time() );
							array_push( $A_gid, $gid );
							array_push( $A_ip, $to_ip );
							array_push( $A_port, $to_port );
							$mid_id = time() + mt_rand(1,200);
							array_push( $A_id, $mid_id );
							push_cmd( $cmd, $mid_id );
							
							socket_sendto( $socket, $cmd, strlen($cmd), 0, $r_ip, $r_port ); 
							echo 'I have send the cmd:'.$cmd."\r\n";
							break;
							
						case 'R':						// 指令结果反馈
							parse_R( $buf, $gid, $id, $res );
							$key = array_search( $id, $A_id );
							if( $key===false )
								break;
							
							if( $A_gid[$key]===$gid) {
								socket_sendto( $socket, $res, strlen($res), 0, $A_ip[$key], $A_port[$key] ); 
								unset( $A_gid[$key] );
								unset( $A_id[$key] );
								unset( $A_t[$key] );
								unset( $A_ip[$key] );
								unset( $A_port[$key] );
							}
							break;
							
						default:
							break;
					}
				}
		}
	}
	
//--------------------------------------------------------------------------------------------------------
//			sub_funs 
//--------------------------------------------------------------------------------------------------------
// [dev_gid,start,timestamp,p,t,f,r]
	function parse_data( $data_str ) {
		$mid_str = explode( "[", $data_str );
		$mid_str = explode( "]", $mid_str[1] );
		if( count($mid_str)>0 ) {
			$segs = explode(",", $mid_str[0] );
			
			$gid = $segs[0];
			$start = $segs[1];
			$time = $segs[2];
			$p = $segs[3];
			$t = $segs[4];
			$f = $segs[5];
			$r = $segs[6];
			if( $time=='null' )
				$time = time();
			
			save_data( $gid, $start, $time, $p, $t, $f, $r );
		}
	}
	
	// I[gid]
	function parse_I( $data_str ) {
		$mid_str = explode( "[", $data_str );
		$mid_str = explode( "]", $mid_str[1] );
		if( count($mid_str)>0 ) {
			$gid = $mid_str[0];
			//var_dump( $gid );
			//echo "\n";
			return $gid;
		}
	}	
	
	// S[gid,指令]
	function parse_S( $data_str, &$gid, &$cmd ) {
		$mid_str = explode( "[", $data_str );
		$mid_str = explode( "]", $mid_str[1] );
		if( count($mid_str)>0 ) {
			$segs = explode(",", $mid_str[0] );
			$gid = $segs[0];
			$cmd = '['.$segs[1].']';
		}
	}
	
	// R[gid,id,res]
	// s输入的为 [gid,id,res]
	function parse_R( $data_str, &$gid, &$id, &$res ) {
		$mid_str = explode( "[", $data_str );
		$mid_str = explode( "]", $mid_str[1] );
		if( count($mid_str)>0 ) {
			$segs = explode(",", $mid_str[0] );
			$gid = $segs[0];
			$id = $segs[1];
			$res = $segs[2];
		}
	}
	
	// old cmd = [××]
	// new cmd = [×× str]
	function push_cmd( &$cmd, $str ) {
		$mid_str = explode( "]", $cmd );
		if( count($mid_str)>0 ) {
			$cmd = $mid_str[0].' '.$str.']';
		}
	}
	
	function get_local_ip() {
		$preg = "/\A((([0-9]?[0-9])|(1[0-9]{2})|(2[0-4][0-9])|(25[0-5]))\.){3}(([0-9]?[0-9])|(1[0-9]{2})|(2[0-4][0-9])|(25[0-5]))\Z/";
		exec ( "ifconfig" , $out , $stats );
		if( !empty($out) ) {
			if( isset($out[1]) && strstr($out[1],'addr:') ) {
				$tmpArray = explode( ":" , $out[1] );
				$tmpIp = explode( " " , $tmpArray[1] );
				if( preg_match($preg,trim($tmpIp[0])) ) {
					return trim( $tmpIp[0] );
				}
			}
		}
		return '127.0.0.1' ;
	} 

/*------------------------------------------------------------------------
			mysql funs
---------------------------------------------------------------------------*/

	function connect_mysql( $mysql_user, $mysql_pass ) {
		$con = mysql_connect( "localhost", $mysql_user, $mysql_pass );
		if ( !$con )
			return '';
	
		mysql_query("SET NAMES 'utf8'", $con);
		return $con;
	}
	
	function save_local_ip_port( $gid, $lip, $lport ) {
		global $mysql_user, $mysql_pass;
		$con = connect_mysql( $mysql_user, $mysql_pass );
		if( empty($con) )
			return;
		
		$sql_str = "UPDATE hx_k_db.dev_t SET l_ip='".$lip."', l_port=".$lport." WHERE gid='".$gid."'";
		mysql_query( $sql_str, $con );
		
		mysql_close($con);
	}
	
	function save_remote_ip_port( $gid, $rip, $rport ) {
		global $mysql_user, $mysql_pass;
		$con = connect_mysql( $mysql_user, $mysql_pass );
		if( empty($con) )
			return;
		
		$sql_str = "UPDATE hx_k_db.dev_t SET d_ip='".$rip."', d_port=".$rport." WHERE gid='".$gid."'";
		mysql_query( $sql_str, $con );
		
		mysql_close($con);
	}

	// $p - 压力
	// $t - 温度
	// $f - 流量
	// $r - 阻力
	// 输入参数皆为字符串
	function save_data( $gid, $start, $time, $p, $t, $f, $r ) {
		global $mysql_user, $mysql_pass;
		$con = connect_mysql( $mysql_user, $mysql_pass );
		if( empty($con) )
			return;
		
		$sql_str = sprintf( "INSERT INTO hx_k_db.data_t (dev_id,v_name,value,time,batch) VALUES ('%s','%s',%f,%d,%d)", $gid,'p',$p,$time,$start );
		mysql_query( $sql_str, $con );

		$sql_str = sprintf( "INSERT INTO hx_k_db.data_t (dev_id,v_name,value,time,batch) VALUES ('%s','%s',%f,%d,%d)", $gid,'t',$t,$time,$start );
		mysql_query( $sql_str, $con );
		
		$sql_str = sprintf( "INSERT INTO hx_k_db.data_t (dev_id,v_name,value,time,batch) VALUES ('%s','%s',%f,%d,%d)", $gid,'f',$f,$time,$start );
		mysql_query( $sql_str, $con );
		
		$sql_str = sprintf( "INSERT INTO hx_k_db.data_t (dev_id,v_name,value,time,batch) VALUES ('%s','%s',%f,%d,%d)", $gid,'r',$r,$time,$start );
		mysql_query( $sql_str, $con );
		
		mysql_close($con);
	}
	
	function get_remote_ip_port( $gid, &$r_ip, &$r_port ) {
		global $mysql_user, $mysql_pass;
		$con = connect_mysql( $mysql_user, $mysql_pass );
		if( empty($con) )
			return;
		
		$sql_str = sprintf( "SELECT d_ip, d_port FROM hx_k_db.dev_t WHERE gid='%s'", $gid );
		$res = mysql_query( $sql_str, $con );
		$t = mysql_fetch_array( $res );
		
		$r_ip = $t[0];
		$r_port = intval( $t[1] );
		
		mysql_free_result( $res );
		mysql_close($con);
	}

?>