RemoteService
=============

Simple PHP class that allows two servers exchange crypted data and call remote classes static methods.

Each data transaction consists of request and reply. All data transactions are independent/
Service that makes request is called in documentation "asking service", and other service replies
this request and it's called "replying service". After it they can change roles but it will be other data
transaction. It's normal situation that one service can only ask, and other only reply.

 <example>
 // Asking service: we want to ask something
 $data = RemoteLib::ask(
 		'http://remote/request.php',
 		'my-id',
 		'secret-key',
 		'Testcommand::defaultMethod',
 		array('id' => 10)
 );

 if ($data === false) echo "Error";

 // Replying service: we want to reply something
 print RemoteLib::reply(
 			'secret-key',
 			'/local/path/to/executable/models/'
 		);
 </example>

