<?php
date_default_timezone_set("PRC");

// rfc5905标准 https://datatracker.ietf.org/doc/html/rfc5905

// 用来演示的服务器
$server = 'ntp.tencent.com';

/**
 *  UTP时间戳与UNIX标准时间戳之前的差值
 *  UTP时间戳： 1900年1月1日00:00:00 以来的秒数
 *  UNIX时间戳： 1970年1月1日00:00:00 以来的秒数
 *  计算方法： (70 * 365 + 17) * 24 * 60 * 60
 */
$epoch_convert = 2208988800;

/**
 * LI (leap indicator) 闰秒标识器, 2位的二进制整数，预报当天最近分钟里的被插入或者删除的闰秒秒数
 * 00 无预告 | 01 最近一分钟有61秒 | 10 最近1分钟有59秒 | 11 警告状态(时钟未同步)
 * 
 */
$header = '00';

/**
 * VN (version number) VN版本号，3位二进制整数
 * 目前版本号为3,换算成2进制为011
 */
$header .= sprintf('%03d',decbin(3));
/**
 * Mode (association mode) 模式，3位二进制整数
 * 0 未定义 | 1 主动对等模式 | 2 为被动对等模式 | 3 为客户端模式 | 4 为服务器模式 | 5 为广播模式或组播模式 | 6 为该消息由NTP控制
 */
$header .= sprintf('%03d',decbin(3));

// 构造请求主体头部,直接转化成ascii，长度为1
$request_packet = chr(bindec($header));

// 用udp方式清求服务器的123端口
$socket = @fsockopen('udp://' . $server, 123, $err_no, $err_str, 1);

if (!$socket) die("连接NTP服务器失败");  


if ($socket) {

  /** 占位符
   * 这里的结构其实是包含 Stratum，Poll, Precision等，长度为39，因为发请求时全部为0，则直接全部给0值占位即可
   * 具体参考FRC5905 18页
   */
  for ($j = 1; $j < 40; $j++) {
    $request_packet .= chr(0x0);
  }
  /**
   * 本地发送请求时的Unix时间戳
   */
  list($t1_frac, $t1_sec) = explode(' ', microtime());
  $t1 = $t1_sec + $t1_frac;

  /**
   * 本地发送请求时的NTP时间戳
   * 此处即为图中T1.客户端发送NTP请求的时间戳
   */
  $t1_sec = $t1_sec + $epoch_convert;

  // 将时间中的小于1秒的部分转换成32位二进制，直接使用补码即可, 再转化为10位10进制
  $t1_frac = sprintf('%010d', round($t1_frac * (1 << 32)));

  /**
   * 将秒的整数部分和小数部分都打包成整型大字节序
   */
  $t1_packed = pack('N', $t1_sec) . pack('N', $t1_frac);

  // 然后将其拼接在请求包上
  $request_packet .= $t1_packed;

  // 发起请求
  if (fwrite($socket, $request_packet)) {
    // 设置超时时间为1秒
    stream_set_timeout($socket, 1);
    // 数据包长度是48位，所以直接一次读到即可，不必浪费资源
    $response = fread($socket, 48);
    // 收到的时间
    $t4 = microtime(true);
  }
  fclose($socket);

  // 解包，包的格式是12位长整数, 类似golang的结构体：struct { value int64 }
  $unpack0 = unpack('N12', $response);

  // 整数部分
  // NTP服务器收到的客户端发送包的时间戳，即T1
  $t1_ntp = sprintf('%u', $unpack0[7]) - $epoch_convert + sprintf('%u', $unpack0[8]) / (1 << 32);
  // NTP服务器收到NTP请求的时间戳
  $t2 = sprintf('%u', $unpack0[9]) - $epoch_convert + sprintf('%u', $unpack0[10]) / (2 << 32);
  // NTP服务器回复NTP请求的时间戳
  $t3 = sprintf('%u', $unpack0[11]) - $epoch_convert + sprintf('%u', $unpack0[12]) / (1 << 32);
  // echo  $t1_unix . "\n" . $t1_ntp . "\n" . $t2 . "\n" . $t3 . "\n";

  // 从返回值里解析出ascii部分
  $unpack1 = unpack("C12", $response);

  // 把返回的头部请求转化成2进制，然后根据位数取出相应的信息
  $header_response = decbin($unpack1[1]);
  // echo $header_response . "\n";

  // 结构为8位十进制数
  $header_response = sprintf('%08d', $header_response);
  // echo $header_response . "\n";

  // MODE,位于串的后3位
  $mode_response = bindec(substr($header_response, -3));

  // VN
  $vn_response = bindec(substr($header_response, -6, 3));
  echo '   本次授时服务器：' . $server . "\n";
  /**
   * Stratum: 系统时钟的层数
   * 范围1 ~ 16
   * 层数为1的时钟精度最高，精度从1依次减少到16，层数为1~6的时钟处于异步状态，不是基准时钟
   */
  echo '  系统时钟的层数: ' . $unpack1[2] . "                            \n";

  // 计算延时，$t4 - $t1 为总延时，假定延迟来回相等，取一半，再减去NTP服务器自身的延迟，大约就是接近真实的延迟
  $delay = ($t4 - $t1) / 2  - ($t3 - $t2);

  // NTP服务器的真实时间
  $ntp_time =  $t3 - $delay;
  $ntp_time_explode = explode('.', $ntp_time);
  $ntp_time_formatted = date('Y-m-d H:i:s', $ntp_time_explode[0]).'.'.$ntp_time_explode[1];
  echo '  授时服务器目前时间:' . $ntp_time_formatted . "  \n";

  // 服务器当前时间信息
  $server_time =  microtime();
  $server_time_explode = explode(' ', $server_time);
  $server_time_micro = round($server_time_explode[0], 4);

  $server_time_formatted = date('Y-m-d H:i:s', time()) .'.'. substr($server_time_micro,2);

  echo '  本地服务器目前时间:' . $server_time_formatted . "  \n";
}