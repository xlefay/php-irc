<?php
/**
 * Class IRC
 */
class IRC
{
    /**
     * Default config.
     *
     * @var array
     */
    private $config =   array(
        'nickname'      =>  'x?',
        'alt_nickname'  =>  'x?',
        'real_name'     =>  'x?',
        'owner'         =>  'xlefay',
        'server'        =>  'irc.soylentnews.org:6667',
        'ssl'           =>  false,
        'network'       =>  'SoylentNews',
        'timeout'       =>  30
    );

    /**
     * The socket.
     *
     * @var null
     */
    private $socket =   null;

    /**
     * Events.
     *
     * @var array
     */
    private $events =   array(

    );

    /**
     * Channel information.
     *
     * @var array
     */
    private $chans  =   array(

    );

    /**
     * Constructor.
     *
     * @param $config
     */
    public function __construct(Array $config)
    {
        // Merge the configs.
        $this->config   = (object) array_merge($this->config, $config);

        // Replace questions marks in config entries..
        foreach ($this->config as &$val)
        {
            $val    = str_replace('?', mt_rand(0, 5000), $val);
        }

        // Verify the server is valid.
        if (!strstr($this->config->server, ':'))
        {
            // Set the default port.
            $this->config->server .= ':6667';
        }

        $this->events['pong']   = function($data) //use ($this)
        {
            $this->send('PONG :' . $data->sender);
        };
    }

    /**
     * Connect to IRC and authenticate.
     *
     * @param callable $before
     * @param callable $after
     * @return bool
     * @throws Exception
     */
    public function connect(Closure $before = null, Closure $after = null)
    {
        // "Before" closure.
        if (!is_null($before))
        {
            $before();
        }

        // Check if there's already a connection.
        if (!is_null($this->socket) && get_resource_type($this->socket) == 'stream')
        {
            return false;
        }

        // Get config variables.
        extract((array) $this->config, EXTR_PREFIX_ALL, 'config');

        // Get the server and port.
        list($server, $port) = explode(':', $config_server);

        // Check if we're using SSL.
        if ($config_ssl)
        {
            $server = 'ssl://' . $server;
        }

        // Create the connection.
        $this->socket   = fsockopen($server, $port, $err_no, $err_str, $this->config->timeout);

        // Check if all went well.
        if (!$this->socket)
        {
            throw new \Exception("Cannot establish connection to $server:$port -- $err_no: $err_str");
        }

        // Authenticate with IRC.
        if (isset($config_password))
        {
            $this->send('PASS ' . $config_password);
        }

        // Send our nickname.
        $this->send('NICK ' . $config_nickname);

        // Todo, see if our nickname is already taken.

        // Send our username & real name and set ourselves as invisible.
        $this->send('USER ' . $config_nickname . ' 8 * :' . $config_real_name);

        // Attach to stream till we're at the end of the motd.
        $attach = true;

        while ($attach && $data = $this->get())
        {
            echo "< " . implode(' ', (array) $data) . "\n";

            // Looking up our hostname.
            if ($data->event == '001')
            {
                echo "!! Connected!\n";
            }

            // End of motd.
            if ($data->event == '376')
            {
                echo "!! EOM!\n";

                // Detach.
                $attach = false;
            }

            //var_dump($data);
        }

        // "After" closure.
        if (!is_null($after))
        {
            $after();
        }
    }

    /**
     * Send data.
     *
     * @param $data
     */
    public function send($data)
    {
        // Don't check whether the socket exists, just try to shove it right through. If it doesn't exist, PHP will
        // show an error anyway.
        fputs($this->socket, $data . "\n");

        // Debug.
        echo "> " . $data . "\n";
    }

    /**
     * Split and organize incoming data, neatly.
     *
     * @param $line
     * @return mixed
     */
    public function parse($line)
    {
        // Trim incoming strings.
        $line   = trim($line);

        // Split the information.
        $data   = explode(' ', $line);

        // Output.
        $output = new Stdclass;

        // Check what kind of string it is.
        if (substr($data[0], 0, 1) == ':')
        {
            // The sender.
            $output->sender = substr(array_shift($data), 1);

            // The event is in the second field.
            $output->event  = strtolower(array_shift($data));

            //echo "Event: \n"; var_dump($output->event);

            // There's a source, check if it's an user.
            if (strstr($data[0], '@'))
            {
                // It's an user.
            }
            else
            {
                // Events.
                switch ($output->event)
                {
                    // Numerics & Notices.
                    default:
                        // Ignore the first entry in the data array, it's useless. (it's our nickname)
                        array_shift($data);
                        break;
                }
            }

            // The message.
            $output->message    = implode(' ', $data);
        }

        return (object) (count($output) > 0) ? $output : $data;
    }

    /**
     * Get the data from the socket.
     *
     * @return string
     */
    public function get()
    {
        // Get the data.
        $data   = fgets($this->socket);

        // Parse it.
        $data   = $this->parse($data);

        // Check if there's an event.
        if (isset($data->event) && in_array($data->event, $this->events))
        {
            // Temporary variable to hold "something" in.
            $event  = $this->events[$data->event];

            if (is_closure($event))
            {
                $event($data);
            }
        }

        // hooks.

        // Return the data.
        return $data;
    }
}