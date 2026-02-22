#!/bin/bash
# SPENCE Vision Watcher v4.3 (Consumption-Aware Unit Selection)

UPLOADS_DIR="/srv/jaaaames.com/spence/uploads"
DB_PATH="/srv/jaaaames.com/spence/database/spence.db"
CORE_DIR="/srv/jaaaames.com/spence/core"
GEMINI_API_KEY=$GEMINI_API_KEY

echo "Spence Vision Watcher v4.3. Monitoring..."

while true; do
    PENDING_JOB=$(sqlite3 "$DB_PATH" "SELECT id, file_path FROM jobs WHERE status = 'pending' LIMIT 1;")
    
    if [ ! -z "$PENDING_JOB" ]; then
        JOB_ID=$(echo "$PENDING_JOB" | cut -d'|' -f1)
        FILE_PATH=$(echo "$PENDING_JOB" | cut -d'|' -f2)
        
        echo "[$(date)] Processing Job #$JOB_ID: $FILE_PATH"
        sqlite3 "$DB_PATH" "UPDATE jobs SET message = 'Analyzing with Consumption-Aware Vision...' WHERE id = $JOB_ID;"
        
        MIME_TYPE="image/jpeg"
        if [[ "$FILE_PATH" == *.png ]]; then MIME_TYPE="image/png"; fi
        B64_DATA=$(base64 -w0 "$FILE_PATH")
        
        PAYLOAD_FILE="/tmp/gemini_payload_$JOB_ID.json"
        cat <<EOF > "$PAYLOAD_FILE"
{
  "contents": [{
    "parts": [
      { "text": "Task: High-precision receipt extraction for Australian Supermarkets (Woolworths, Coles, Aldi).

Format Logic:
1. Standard Line: 'PRODUCT NAME' on the left, 'TOTAL PRICE' on the far right.
2. Multiples Handling:
   - Woolworths: The total price for multiples is usually on the second line alongside quantity info (e.g., '2 @ $3.00').
   - Coles/Aldi: The total price for multiples is often on the first line with the product name.
   - Always prioritize the value in the far-right column as the line's price.
3. Sticker Fusion: If product stickers (e.g. deli weight/unit-price) are visible, use them to resolve missing weight data.

Extraction Instructions:
- Extract EVERY line item. 
- Product Name: Fix abbreviations and OCR noise. Expand to human-readable Title Case (e.g., 'WHL BRCK CHK 1.5KG' -> 'Whole Bricked Chicken').
- Multiples: If quantity > 1 (e.g. 2 cans), return a separate JSON object for EACH unit. 

Unit & Consumption Protocol (CRITICAL):
1. Normalization: Strip all weight, volume, and count descriptors from the 'product' name (e.g., '600g', '2L', '18 pack', '1.5kg'). The 'product' name should be the clean canonical name.
2. 'unit' Selection: Determine based on how the item is CONSUMED, not just how it is sold.
   - 'ea' (Each): Use ONLY for items naturally consumed as whole units (e.g., eggs, buns, individual avocados, multi-pack snacks). 
     - Example: 'Milk Buns 4pk 300g' -> product='Milk Buns', unit='ea', amount=4, weight_per_ea=0.075.
     - Example: 'Free Range Eggs 600g' -> product='Free Range Eggs', unit='ea', amount=12, weight_per_ea=0.050.
   - 'kg' / 'L' (Weight/Volume): Use for items usually consumed in partial portions (e.g., cheese blocks/slices, deli meats, tubs of yogurt, bottles of oil). 
     - Example: 'Burger Slices 200g' -> product='Burger Slices', unit='kg', amount=0.200, weight_per_ea=1.0.
     - Example: 'Olive Oil 750ml' -> product='Olive Oil', unit='L', amount=0.750, weight_per_ea=1.0.

Return ONLY a valid JSON array of objects." },
      { "inline_data": { "mime_type": "$MIME_TYPE", "data": "$B64_DATA" } }
    ]
  }],
  "generationConfig": {
    "responseMimeType": "application/json",
    "responseJsonSchema": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "product": {"type": "string"},
          "amount": {"type": "number"},
          "unit": {"type": "string", "enum": ["kg", "L", "ea"]},
          "price": {"type": "number"},
          "weight_per_ea": {"type": "number"},
          "location": {"type": "string", "enum": ["Pantry", "Fridge", "Freezer"]},
          "category": {"type": "string", "enum": ["Proteins", "Dairy", "Bread", "Fruit and Veg", "Cereals/Grains", "Snacks/Confectionary", "Drinks", "Other"]},
          "kj_per_100": {"type": "number"},
          "protein_per_100": {"type": "number"},
          "fat_per_100": {"type": "number"},
          "carbs_per_100": {"type": "number"}
        },
        "required": ["product", "amount", "unit", "price", "weight_per_ea", "location", "category", "kj_per_100", "protein_per_100", "fat_per_100", "carbs_per_100"]
      }
    }
  }
}
EOF

        RESPONSE=$(curl -s -X POST "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key=$GEMINI_API_KEY" \
            -H 'Content-Type: application/json' -d @"$PAYLOAD_FILE")
        
        rm "$PAYLOAD_FILE"
        CLEAN_JSON=$(echo "$RESPONSE" | jq -r '.candidates[0].content.parts[0].text // empty')
        
        if [ ! -z "$CLEAN_JSON" ] && jq . <<< "$CLEAN_JSON" > /dev/null 2>&1; then
            echo "$CLEAN_JSON" > /tmp/pantry_json.txt
            sqlite3 "$DB_PATH" "UPDATE jobs SET status = 'completed', result_json = readfile('/tmp/pantry_json.txt'), message = 'Ingesting...' WHERE id = $JOB_ID;"
            php "$CORE_DIR/bridge_ingest.php"
        else
            sqlite3 "$DB_PATH" "UPDATE jobs SET status = 'failed', message = 'Vision failed.' WHERE id = $JOB_ID;"
        fi
    fi
    sleep 5
done
