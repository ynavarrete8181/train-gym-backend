import re
import sys

def parse_sql(filename):
    tables = {}
    schemas = set()
    current_table = None
    
    with open(filename, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            # Schemas
            m_schema = re.match(r'CREATE SCHEMA (\w+);', line)
            if m_schema:
                schemas.add(m_schema.group(1))
                continue
                
            # Tables
            m_table = re.match(r'CREATE TABLE ([\w\.]+)\s*\(', line)
            if m_table:
                current_table = m_table.group(1)
                if current_table not in tables:
                    tables[current_table] = []
                continue
                
            if current_table:
                if line.startswith(');'):
                    current_table = None
                elif line and not line.startswith('--'):
                    # Simplify column definitions for comparison
                    col_def = re.sub(r'\s+', ' ', line).strip(',')
                    if col_def:
                        tables[current_table].append(col_def)
    
    return schemas, tables

local_schemas, local_tables = parse_sql('local_schema.sql')
prod_schemas, prod_tables = parse_sql('prod_schema.sql')

print("=== SCHEMAS ===")
missing_schemas = local_schemas - prod_schemas
for s in missing_schemas:
    if s not in ('public',):
        print(f"Missing Schema in Prod: {s}")

print("\n=== TABLES ===")
missing_tables = set(local_tables.keys()) - set(prod_tables.keys())
for t in missing_tables:
    print(f"Missing Table in Prod: {t}")

print("\n=== COLUMNS IN EXISTING TABLES ===")
common_tables = set(local_tables.keys()).intersection(set(prod_tables.keys()))
for t in common_tables:
    l_cols = [c.split()[0] for c in local_tables[t] if c.split()]
    p_cols = [c.split()[0] for c in prod_tables[t] if c.split()]
    
    for c in l_cols:
        if c not in p_cols and not c.startswith('CONSTRAINT'):
            print(f"Table {t} missing column in prod: {c}")

