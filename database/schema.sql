CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT NOT NULL, kj_per_100 REAL DEFAULT 0, protein_per_100 REAL DEFAULT 0, fat_per_100 REAL DEFAULT 0, carb_per_100 REAL DEFAULT 0, weight_per_ea REAL DEFAULT 1.0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, merges_into INTEGER REFERENCES products(id), last_unit_cost REAL DEFAULT 0, recipe_id INTEGER REFERENCES recipes(id), type TEXT DEFAULT 'raw' CHECK(type IN ('raw', 'cooked')), base_unit TEXT DEFAULT 'kg', is_dropped BOOLEAN DEFAULT 0);
CREATE TABLE inventory (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, current_qty REAL NOT NULL, unit TEXT NOT NULL, price_paid REAL DEFAULT 0, location TEXT DEFAULT 'Pantry', expiry_date DATE, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (product_id) REFERENCES products(id));
CREATE TABLE recipes (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, instructions TEXT, yield_serves INTEGER DEFAULT 1, product_id INTEGER REFERENCES products(id), is_active INTEGER DEFAULT 1, parent_recipe_id INTEGER REFERENCES recipes(id), version INTEGER DEFAULT 1, tags TEXT);
CREATE TABLE recipe_ingredients (id INTEGER PRIMARY KEY AUTOINCREMENT, recipe_id INTEGER NOT NULL, product_id INTEGER NOT NULL, amount REAL NOT NULL, unit TEXT NOT NULL, wastage_factor REAL DEFAULT 1.0, FOREIGN KEY (recipe_id) REFERENCES recipes(id), FOREIGN KEY (product_id) REFERENCES products(id));
CREATE TABLE consumption_log (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, recipe_id INTEGER, amount REAL, unit TEXT, kj REAL, protein REAL, fat REAL, carb REAL, consumed_at DATETIME DEFAULT CURRENT_TIMESTAMP, unit_cost REAL DEFAULT 0);
CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, file_path TEXT, status TEXT DEFAULT 'pending', message TEXT, result_json TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE product_aliases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_name TEXT NOT NULL UNIQUE,
    canonical_product_id INTEGER NOT NULL REFERENCES products(id),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE user_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    dob DATE,
    gender TEXT,
    height_cm REAL,
    activity_rate REAL, -- multiplier e.g. 1.2 to 1.9
    weekly_budget REAL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE user_vitals_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES user_profiles(id),
    weight_kg REAL NOT NULL,
    body_fat_pct REAL,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE user_goals_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES user_profiles(id),
    goal_type TEXT, -- weight loss, muscle gain, maintenance
    target_kj REAL,
    target_protein_g REAL,
    target_fat_g REAL,
    target_carb_g REAL,
    cost_limit_daily REAL,
    start_date DATE DEFAULT CURRENT_DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE recipe_tags_master (tag_name TEXT PRIMARY KEY);
