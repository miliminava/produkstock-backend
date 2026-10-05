<?php

class Create_products_table {

    private $_lava;
    protected $dbforge;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        // Write your "UP" migration here
        $this->_lava->dbforge->add_field([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => TRUE,
                'auto_increment' => TRUE
            ],
            'product_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => FALSE,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => TRUE,
            ],
            'price' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => FALSE,
            ],
            'quantity' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => FALSE,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => FALSE,
                'default' => 'CURRENT_TIMESTAMP',
            ]
        ]);

        $this->_lava->dbforge->add_key('id', TRUE);
        $this->_lava->dbforge->create_table('products');
    }

    public function down()
    {
        // Write your "DOWN" migration here
        $this->_lava->dbforge->drop_table('products');
    }
}