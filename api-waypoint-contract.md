# API Waypoint, response contract (v1 draft)

Wire format published by the `hygo/laravel-api-waypoint` package and consumed by the API Explorer Central App.

Draft wire format for the Laravel package's dev-only endpoints, consumed by the local Central App.

**Conventions used throughout**

- Everything lives under `/v1/api-waypoint`, registered only when `app()->environment(['local','staging'])` and `config('api-waypoint.enabled') === true`.
- Every request carries `X-Api-Waypoint-Secret`. No secret, no route (404, not 403, so the endpoint is not discoverable).
- Field schemas are plain **JSON Schema draft 2020-12** plus two extension namespaces:
  - `x-laravel`, the Laravel facts JSON Schema cannot express (`exists:`, `unique:`, conditional rules, enum class).
  - `x-faker`, an abstract generation *strategy*. The API app never mentions faker.js method names, the Central App maps strategy to a generator. That keeps the API package free of any knowledge about the Central App's generator library.
- Data objects are defined once under `components.data_objects` and referenced by `$ref`, because a single Data class is reused across endpoints. Component keys are `Module.ClassName`, not FQCNs, to keep JSON Pointers clean. The FQCN is carried as a property.

---

## 1. `GET /v1/api-waypoint`

The whole catalogue, one document. A few hundred endpoints is a couple of MB before gzip, so no pagination.

**Request**

```http
GET /v1/api-waypoint HTTP/1.1
Host: acme-orders.test
X-Api-Waypoint-Secret: 7f3c9a1e...
Accept: application/json
If-None-Match: "sha256:9b1c4e8f2a7d5063"
```

**Response headers**

```http
HTTP/1.1 200 OK
Content-Type: application/json
ETag: "sha256:4a8e2f9c1b7d3056"
Cache-Control: no-store
X-Api-Waypoint-Format: 1.0
```

**Response body**

```json
{
  "schema_format_version": "1.0",
  "generated_at": "2026-08-19T09:14:22+10:00",
  "schema_hash": "sha256:4a8e2f9c1b7d3056",
  "application": {
    "key": "acme-orders",
    "name": "Acme Orders API",
    "environment": "local",
    "base_url": "https://acme-orders.test",
    "api_prefix": "/api/v1",
    "laravel_version": "13.4.2",
    "package_version": "0.4.1",
    "git": {
      "branch": "feature/split-payments",
      "commit": "e91af03"
    }
  },
  "capabilities": {
    "references": true,
    "tokens": true,
    "scenarios": true,
    "reset": false,
    "response_snapshots": true
  },
  "auth": {
    "schemes": [
      {
        "id": "sanctum_bearer",
        "type": "http",
        "scheme": "bearer",
        "header": "Authorization",
        "description": "Sanctum personal access token. Mint one via POST /v1/api-waypoint/tokens."
      }
    ],
    "test_roles": ["admin", "staff", "customer"]
  },
  "modules": [
    { "key": "orders", "name": "Orders", "endpoint_count": 11 },
    { "key": "customers", "name": "Customers", "endpoint_count": 7 },
    { "key": "billing", "name": "Billing", "endpoint_count": 14 }
  ],
  "endpoints": [
    {
      "id": "orders.store",
      "hash": "sha256:c30f7b91ae42",
      "module": "orders",
      "route_name": "api.v1.orders.store",
      "method": "POST",
      "uri": "/api/v1/orders",
      "path_parameters": [],
      "summary": "Create an order",
      "deprecated": false,
      "action": {
        "class": "Modules\\Orders\\Actions\\CreateOrder",
        "type": "laravel-actions",
        "as_controller": true
      },
      "middleware": ["api", "auth:sanctum", "throttle:60,1"],
      "auth": {
        "required": true,
        "scheme": "sanctum_bearer",
        "abilities": ["orders:create"],
        "roles": ["admin", "staff"]
      },
      "input": {
        "location": "body",
        "content_type": "application/json",
        "data_class": "Modules\\Orders\\Data\\CreateOrderData",
        "schema": { "$ref": "#/components/data_objects/Orders.CreateOrderData" }
      },
      "query": null,
      "response": {
        "success_status": 201,
        "transformer": "Modules\\Orders\\Transformers\\OrderTransformer",
        "serializer": "Spatie\\Fractal\\ArraySerializer",
        "shape": "opaque",
        "available_includes": ["customer", "lines", "payments", "lines.product"],
        "default_includes": ["customer"],
        "snapshot": {
          "captured_at": "2026-08-18T16:02:10+10:00",
          "hash": "sha256:1d77be04",
          "example": {
            "data": {
              "id": "0192f4c1-7a3b-7c2e-9f01-6b2d8e4a5c11",
              "reference": "ORD-004182",
              "status": "draft",
              "channel": "web",
              "total_cents": 18450,
              "currency": "AUD",
              "placed_at": "2026-08-18T16:02:10+10:00",
              "customer": {
                "id": "0192f4b0-11c2-7a44-8bd1-2f9e7c0a1b33",
                "name": "Marguerite Okonkwo"
              }
            }
          }
        },
        "errors": [
          { "status": 422, "schema": { "$ref": "#/components/responses/ValidationError" } },
          { "status": 403, "schema": { "$ref": "#/components/responses/Forbidden" } }
        ]
      }
    },
    {
      "id": "orders.index",
      "hash": "sha256:88b2ce17df90",
      "module": "orders",
      "route_name": "api.v1.orders.index",
      "method": "GET",
      "uri": "/api/v1/orders",
      "path_parameters": [],
      "summary": "List orders",
      "deprecated": false,
      "action": {
        "class": "Modules\\Orders\\Actions\\ListOrders",
        "type": "laravel-actions",
        "as_controller": true
      },
      "middleware": ["api", "auth:sanctum"],
      "auth": {
        "required": true,
        "scheme": "sanctum_bearer",
        "abilities": ["orders:read"],
        "roles": ["admin", "staff", "customer"]
      },
      "input": null,
      "query": {
        "source": "spatie/laravel-query-builder",
        "filters": [
          {
            "name": "status",
            "query_key": "filter[status]",
            "type": "exact",
            "multiple": true,
            "allowed_values": ["draft", "awaiting_payment", "paid", "cancelled"],
            "x-faker": { "strategy": "enum" }
          },
          {
            "name": "reference",
            "query_key": "filter[reference]",
            "type": "partial",
            "multiple": false,
            "x-faker": { "strategy": "pattern", "pattern": "ORD-######" }
          },
          {
            "name": "customer.name",
            "query_key": "filter[customer.name]",
            "type": "partial",
            "multiple": false,
            "relation": "customer",
            "x-faker": { "strategy": "person.lastName" }
          },
          {
            "name": "placed_between",
            "query_key": "filter[placed_between]",
            "type": "custom",
            "class": "Modules\\Orders\\Filters\\PlacedBetweenFilter",
            "value_hint": "date_range_csv",
            "x-faker": { "strategy": "date_range", "format": "Y-m-d", "separator": "," }
          }
        ],
        "sorts": [
          { "name": "placed_at", "query_key": "sort", "default": true, "default_direction": "desc" },
          { "name": "total_cents", "query_key": "sort", "default": false },
          { "name": "reference", "query_key": "sort", "default": false }
        ],
        "includes": [
          { "name": "customer", "type": "relationship", "count_variant": false },
          { "name": "lines", "type": "relationship", "count_variant": true },
          { "name": "lines.product", "type": "relationship", "count_variant": false },
          { "name": "payments", "type": "relationship", "count_variant": true }
        ],
        "fields": {
          "orders": ["id", "reference", "status", "total_cents", "placed_at"],
          "customers": ["id", "name", "email"]
        },
        "pagination": {
          "style": "page",
          "query_keys": { "page": "page", "per_page": "per_page" },
          "per_page_default": 15,
          "per_page_max": 100
        }
      },
      "response": {
        "success_status": 200,
        "transformer": "Modules\\Orders\\Transformers\\OrderTransformer",
        "serializer": "League\\Fractal\\Serializer\\DataArraySerializer",
        "shape": "collection",
        "available_includes": ["customer", "lines", "payments", "lines.product"],
        "default_includes": [],
        "snapshot": null,
        "errors": [
          { "status": 400, "schema": { "$ref": "#/components/responses/QueryBuilderError" } }
        ]
      }
    },
    {
      "id": "orders.refund",
      "hash": "sha256:2f5a91bb7c03",
      "module": "billing",
      "route_name": "api.v1.orders.refund",
      "method": "POST",
      "uri": "/api/v1/orders/{order}/refund",
      "path_parameters": [
        {
          "name": "order",
          "required": true,
          "binding": { "model": "Modules\\Orders\\Models\\Order", "key": "uuid" },
          "schema": { "type": "string", "format": "uuid" },
          "x-faker": {
            "strategy": "reference",
            "reference": { "table": "orders", "column": "uuid", "constraint": { "status": "paid" } }
          }
        }
      ],
      "summary": "Refund a paid order",
      "deprecated": false,
      "action": {
        "class": "Modules\\Billing\\Actions\\RefundOrder",
        "type": "laravel-actions",
        "as_controller": true
      },
      "middleware": ["api", "auth:sanctum", "can:refund,order"],
      "auth": {
        "required": true,
        "scheme": "sanctum_bearer",
        "abilities": ["billing:refund"],
        "roles": ["admin"]
      },
      "input": {
        "location": "body",
        "content_type": "application/json",
        "data_class": "Modules\\Billing\\Data\\RefundOrderData",
        "schema": { "$ref": "#/components/data_objects/Billing.RefundOrderData" }
      },
      "query": null,
      "response": {
        "success_status": 202,
        "transformer": "Modules\\Billing\\Transformers\\RefundTransformer",
        "serializer": "Spatie\\Fractal\\ArraySerializer",
        "shape": "opaque",
        "available_includes": ["order"],
        "default_includes": [],
        "snapshot": null,
        "errors": [
          { "status": 409, "schema": { "$ref": "#/components/responses/DomainConflict" } },
          { "status": 422, "schema": { "$ref": "#/components/responses/ValidationError" } }
        ]
      },
      "preconditions": [
        {
          "description": "Order must be in the paid state",
          "scenario": "paid_order"
        }
      ]
    }
  ],
  "components": {
    "data_objects": {
      "Orders.CreateOrderData": {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": "CreateOrderData",
        "x-laravel": {
          "class": "Modules\\Orders\\Data\\CreateOrderData",
          "hash": "sha256:5c1e77a0"
        },
        "type": "object",
        "additionalProperties": false,
        "required": ["customer_id", "channel", "lines"],
        "properties": {
          "customer_id": {
            "type": "string",
            "format": "uuid",
            "description": "Existing customer to place the order against.",
            "x-laravel": {
              "property": "customerId",
              "input_name": "customer_id",
              "rules": ["required", "uuid", "exists:customers,uuid"],
              "exists": { "table": "customers", "column": "uuid" }
            },
            "x-faker": {
              "strategy": "reference",
              "reference": { "table": "customers", "column": "uuid" }
            }
          },
          "channel": {
            "type": "string",
            "enum": ["web", "phone", "in_store"],
            "x-laravel": {
              "property": "channel",
              "rules": ["required", "enum"],
              "enum_class": "Modules\\Orders\\Enums\\OrderChannel"
            },
            "x-faker": { "strategy": "enum" }
          },
          "reference": {
            "type": ["string", "null"],
            "maxLength": 32,
            "pattern": "^ORD-[0-9]{6}$",
            "x-laravel": {
              "property": "reference",
              "rules": ["nullable", "string", "max:32", "regex:/^ORD-[0-9]{6}$/", "unique:orders,reference"],
              "nullable": true,
              "unique": { "table": "orders", "column": "reference" }
            },
            "x-faker": {
              "strategy": "pattern",
              "pattern": "ORD-######",
              "unique": true,
              "include_probability": 0.6
            }
          },
          "purchase_order_no": {
            "type": ["string", "null"],
            "maxLength": 40,
            "x-laravel": {
              "property": "purchaseOrderNo",
              "rules": ["required_if:channel,phone", "nullable", "string", "max:40"],
              "optional": true,
              "conditional_rules": [
                { "rule": "required_if", "field": "channel", "values": ["phone"] }
              ]
            },
            "x-faker": {
              "strategy": "alphanumeric",
              "length": 10,
              "required_when": { "field": "channel", "in": ["phone"] }
            }
          },
          "notes": {
            "type": ["string", "null"],
            "maxLength": 500,
            "x-laravel": {
              "property": "notes",
              "rules": ["nullable", "string", "max:500"],
              "nullable": true,
              "optional": true
            },
            "x-faker": { "strategy": "sentence", "include_probability": 0.4 }
          },
          "ship_at": {
            "type": ["string", "null"],
            "format": "date-time",
            "x-laravel": {
              "property": "shipAt",
              "rules": ["nullable", "date", "after:today"],
              "nullable": true
            },
            "x-faker": {
              "strategy": "date",
              "range": { "after": "+1 day", "before": "+30 days" },
              "format": "iso8601"
            }
          },
          "lines": {
            "type": "array",
            "minItems": 1,
            "maxItems": 50,
            "items": { "$ref": "#/components/data_objects/Orders.OrderLineData" },
            "x-laravel": {
              "property": "lines",
              "rules": ["required", "array", "min:1", "max:50"],
              "data_collection_of": "Modules\\Orders\\Data\\OrderLineData"
            },
            "x-faker": { "strategy": "collection", "count": { "min": 1, "max": 3 } }
          },
          "metadata": {
            "type": ["object", "null"],
            "additionalProperties": { "type": "string" },
            "x-laravel": {
              "property": "metadata",
              "rules": ["nullable", "array"],
              "nullable": true
            },
            "x-faker": { "strategy": "key_value_map", "count": { "min": 0, "max": 3 } }
          }
        }
      },
      "Orders.OrderLineData": {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": "OrderLineData",
        "x-laravel": {
          "class": "Modules\\Orders\\Data\\OrderLineData",
          "hash": "sha256:9ab30f11"
        },
        "type": "object",
        "additionalProperties": false,
        "required": ["product_id", "quantity"],
        "properties": {
          "product_id": {
            "type": "integer",
            "minimum": 1,
            "x-laravel": {
              "property": "productId",
              "rules": ["required", "integer", "exists:products,id"],
              "exists": { "table": "products", "column": "id" }
            },
            "x-faker": {
              "strategy": "reference",
              "reference": { "table": "products", "column": "id", "constraint": { "is_active": true } }
            }
          },
          "quantity": {
            "type": "integer",
            "minimum": 1,
            "maximum": 999,
            "x-laravel": {
              "property": "quantity",
              "rules": ["required", "integer", "min:1", "max:999"]
            },
            "x-faker": { "strategy": "int", "min": 1, "max": 5 }
          },
          "unit_price_cents": {
            "type": ["integer", "null"],
            "minimum": 0,
            "x-laravel": {
              "property": "unitPriceCents",
              "rules": ["nullable", "integer", "min:0"],
              "nullable": true,
              "description": "Overrides the product price when the caller has the pricing ability."
            },
            "x-faker": { "strategy": "int", "min": 100, "max": 50000, "include_probability": 0.2 }
          }
        }
      },
      "Billing.RefundOrderData": {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": "RefundOrderData",
        "x-laravel": {
          "class": "Modules\\Billing\\Data\\RefundOrderData",
          "hash": "sha256:71c4de88"
        },
        "type": "object",
        "additionalProperties": false,
        "required": ["amount_cents", "reason"],
        "properties": {
          "amount_cents": {
            "type": "integer",
            "minimum": 1,
            "x-laravel": {
              "property": "amountCents",
              "rules": ["required", "integer", "min:1", "lte:order_total_cents"],
              "conditional_rules": [
                { "rule": "lte", "field": "order_total_cents", "note": "Bound by the order, not the payload. Central App cannot infer a safe value without the order." }
              ]
            },
            "x-faker": {
              "strategy": "unresolvable",
              "reason": "Depends on the bound order's total. Pin a value or resolve via GET /v1/api-waypoint/references."
            }
          },
          "reason": {
            "type": "string",
            "enum": ["duplicate", "fraudulent", "requested_by_customer", "goods_returned"],
            "x-laravel": {
              "property": "reason",
              "rules": ["required", "enum"],
              "enum_class": "Modules\\Billing\\Enums\\RefundReason"
            },
            "x-faker": { "strategy": "enum" }
          },
          "notify_customer": {
            "type": "boolean",
            "default": true,
            "x-laravel": {
              "property": "notifyCustomer",
              "rules": ["boolean"],
              "optional": true,
              "default": true
            },
            "x-faker": { "strategy": "boolean", "true_probability": 0.8 }
          }
        }
      }
    },
    "responses": {
      "ValidationError": {
        "type": "object",
        "required": ["message", "errors"],
        "properties": {
          "message": { "type": "string" },
          "errors": {
            "type": "object",
            "additionalProperties": { "type": "array", "items": { "type": "string" } }
          }
        },
        "example": {
          "message": "The channel field must be one of: web, phone, in_store.",
          "errors": {
            "channel": ["The selected channel is invalid."],
            "lines.0.product_id": ["The selected product id is invalid."]
          }
        }
      },
      "Forbidden": {
        "type": "object",
        "required": ["message"],
        "properties": { "message": { "type": "string" } },
        "example": { "message": "This action is unauthorized." }
      },
      "DomainConflict": {
        "type": "object",
        "required": ["message", "code"],
        "properties": {
          "message": { "type": "string" },
          "code": { "type": "string" }
        },
        "example": { "message": "Order is not in a refundable state.", "code": "order.not_refundable" }
      },
      "QueryBuilderError": {
        "type": "object",
        "required": ["message"],
        "properties": { "message": { "type": "string" } },
        "example": { "message": "Requested filter(s) `customer_email` are not allowed. Allowed filter(s) are `status, reference, customer.name, placed_between`." }
      }
    }
  },
  "diagnostics": {
    "unmapped_routes": [
      {
        "route_name": "api.v1.reports.export",
        "method": "POST",
        "uri": "/api/v1/reports/export",
        "action": "Modules\\Reporting\\Actions\\ExportReport",
        "reason": "no_data_class",
        "detail": "handle() takes no Data parameter and no inputData() method was found. Falls back to inline $request->validate() which is not introspected."
      }
    ],
    "warnings": [
      {
        "endpoint_id": "orders.refund",
        "code": "unresolvable_field",
        "detail": "RefundOrderData.amount_cents depends on the bound order total."
      },
      {
        "endpoint_id": "orders.index",
        "code": "opaque_response",
        "detail": "Fractal transformer response shape cannot be derived statically. Capture a snapshot to enable response diffing."
      }
    ],
    "counts": {
      "routes_total": 41,
      "routes_mapped": 40,
      "routes_unmapped": 1,
      "data_objects": 27,
      "endpoints_with_snapshots": 12
    }
  }
}
```

---

## 2. Refresh check

Two options, both cheap. Use the manifest when you want partial refresh, the 304 when you just want "anything at all?".

**2a. `GET /v1/api-waypoint` with `If-None-Match`**

```http
HTTP/1.1 304 Not Modified
ETag: "sha256:4a8e2f9c1b7d3056"
X-Api-Waypoint-Format: 1.0
```

**2b. `GET /v1/api-waypoint/manifest`**

Per-endpoint and per-Data-object hashes only. A few KB, so the Central App can show "14 endpoints changed" without pulling the full document, and can decide what to re-diff.

```json
{
  "schema_format_version": "1.0",
  "generated_at": "2026-08-19T09:14:22+10:00",
  "schema_hash": "sha256:4a8e2f9c1b7d3056",
  "application": { "key": "acme-orders", "environment": "local" },
  "endpoints": {
    "orders.store": "sha256:c30f7b91ae42",
    "orders.index": "sha256:88b2ce17df90",
    "orders.refund": "sha256:2f5a91bb7c03"
  },
  "data_objects": {
    "Orders.CreateOrderData": "sha256:5c1e77a0",
    "Orders.OrderLineData": "sha256:9ab30f11",
    "Billing.RefundOrderData": "sha256:71c4de88"
  },
  "removed_since": null
}
```

---

## 3. `GET /v1/api-waypoint/references/{table}/{column}`

Live database lookup so `exists:` fields get real values instead of a random UUID that guarantees a 422. Powers the picker next to the field in the UI.

**Request**

```http
GET /v1/api-waypoint/references/customers/uuid?limit=5&label=name&q=oko HTTP/1.1
X-Api-Waypoint-Secret: 7f3c9a1e...
```

**Response**

```json
{
  "table": "customers",
  "column": "uuid",
  "total_available": 1284,
  "returned": 3,
  "truncated": false,
  "values": [
    {
      "value": "0192f4b0-11c2-7a44-8bd1-2f9e7c0a1b33",
      "label": "Marguerite Okonkwo",
      "context": { "email": "m.okonkwo@example.test", "status": "active" }
    },
    {
      "value": "0192f4b1-8d0e-7b19-a3c4-51f7ba9e2d07",
      "label": "Daniel Okorie",
      "context": { "email": "d.okorie@example.test", "status": "active" }
    },
    {
      "value": "0192f4b2-2266-7f83-95ab-c4e10d773f19",
      "label": "Sunday Okoye",
      "context": { "email": "s.okoye@example.test", "status": "suspended" }
    }
  ]
}
```

With a constraint, as requested by `x-faker.reference.constraint`:

```http
GET /v1/api-waypoint/references/orders/uuid?limit=2&where[status]=paid
```

```json
{
  "table": "orders",
  "column": "uuid",
  "constraint": { "status": "paid" },
  "total_available": 63,
  "returned": 2,
  "truncated": false,
  "values": [
    { "value": "0192f3aa-4b71-7c02-8e15-9d3fc6a80b42", "label": "ORD-004108", "context": { "total_cents": 24900 } },
    { "value": "0192f3ab-cc19-7de4-b076-1a58e29f4c30", "label": "ORD-004109", "context": { "total_cents": 8150 } }
  ]
}
```

Empty is a normal answer and the UI should say so rather than showing an empty dropdown:

```json
{
  "table": "orders",
  "column": "uuid",
  "constraint": { "status": "paid" },
  "total_available": 0,
  "returned": 0,
  "truncated": false,
  "values": [],
  "hint": {
    "message": "No matching records. Run a scenario first.",
    "scenario": "paid_order"
  }
}
```

---

## 4. `POST /v1/api-waypoint/tokens`

No stored bearer tokens anywhere. Dev picks a role in the UI, gets a fresh short-lived token.

**Request**

```json
{
  "role": "admin",
  "ttl_minutes": 120,
  "abilities": ["orders:create", "orders:read", "billing:refund"]
}
```

**Response**

```json
{
  "token": "17|kZ8vQ2mFbN4rT9xL1pJ6cH0aY3wS5dG7eR2uI8oP",
  "header": "Authorization",
  "value_template": "Bearer {token}",
  "role": "admin",
  "user": {
    "id": 3,
    "name": "Waypoint Admin",
    "email": "waypoint+admin@acme-orders.test"
  },
  "abilities": ["orders:create", "orders:read", "billing:refund"],
  "expires_at": "2026-08-19T11:14:22+10:00"
}
```

Rejected role, because `issue_token` must only ever mint for designated waypoint users:

```json
{
  "message": "Role `superuser` is not an allowed waypoint role.",
  "code": "waypoint.role_not_allowed",
  "allowed_roles": ["admin", "staff", "customer"]
}
```

---

## 5. Scenarios

Creates prerequisite state for endpoints that need it, returning enough of the record for the Central App to fill dependent fields.

The request accepts a **scenario name only**, never a class, factory or attribute set. Each host app declares its scenarios in config, so the HTTP surface cannot be used to invoke arbitrary code. See spec 1, section 3.5.

### 5a. `GET /v1/api-waypoint/scenarios`

Lets the Central App render the Setup tab without hardcoding anything.

```json
{
  "scenarios": [
    {
      "name": "paid_order",
      "description": "A paid order with two lines, ready to refund.",
      "parameters": {
        "type": "object",
        "required": [],
        "properties": {
          "channel": {
            "type": "string",
            "enum": ["web", "phone", "in_store"],
            "default": "web"
          },
          "line_count": { "type": "integer", "minimum": 1, "maximum": 5, "default": 2 }
        }
      }
    },
    {
      "name": "suspended_customer",
      "description": "A customer in the suspended state with no open orders.",
      "parameters": { "type": "object", "required": [], "properties": {} }
    }
  ]
}
```

### 5b. `POST /v1/api-waypoint/scenarios`

**Request**

```json
{
  "scenario": "paid_order",
  "parameters": { "channel": "web", "line_count": 2 }
}
```

**Response**

```json
{
  "created": 1,
  "records": [
    {
      "model": "Modules\\Orders\\Models\\Order",
      "key": 4183,
      "route_key": "0192f4d9-6e12-7a80-b3f5-8c1e02a7d914",
      "attributes": {
        "uuid": "0192f4d9-6e12-7a80-b3f5-8c1e02a7d914",
        "reference": "ORD-004183",
        "status": "paid",
        "channel": "web",
        "total_cents": 13200,
        "customer_uuid": "0192f4b0-11c2-7a44-8bd1-2f9e7c0a1b33"
      },
      "related": {
        "lines": [
          { "key": 9901, "product_id": 55, "quantity": 2 },
          { "key": 9902, "product_id": 71, "quantity": 1 }
        ]
      }
    }
  ],
  "scenario": "paid_order",
  "cleanup_token": "scn_01j9x7q4m2"
}
```

`cleanup_token` lets a later `DELETE /v1/api-waypoint/scenarios/{cleanup_token}` undo the run in reverse creation order, so repeated runs do not silt up the dev database.

Unknown scenario name is a 422 that tells the caller what is available, so the Central App can recover without a resync:

```json
{
  "message": "Unknown scenario `paid_invoice`.",
  "code": "waypoint.scenario_unknown",
  "available": ["paid_order", "suspended_customer"]
}
```

---

## 6. Guard failures

Wrong or missing secret, or the package disabled. Deliberately a 404 so the endpoint is not discoverable by probing.

```json
{
  "message": "Not Found."
}
```

Format mismatch, when an older Central App talks to a newer package:

```http
HTTP/1.1 409 Conflict
```

```json
{
  "message": "Unsupported schema format requested.",
  "code": "waypoint.format_unsupported",
  "requested": "1.0",
  "supported": ["2.0", "2.1"],
  "hint": "Update the Central App, or pin the package to a 1.x release."
}
```

---

## Notes for implementation

- **`x-faker.strategy` is a closed vocabulary.** Define it once and version it: `reference`, `enum`, `pattern`, `int`, `float`, `boolean`, `sentence`, `paragraph`, `person.firstName`, `person.lastName`, `internet.email`, `phone`, `date`, `date_range`, `uuid`, `url`, `address.*`, `collection`, `key_value_map`, `alphanumeric`, `timezone`, `mirror`, `distinct_from`, `unresolvable`. An unknown strategy in the Central App should fall back by JSON Schema `type` and log, never throw.
- **`unresolvable` is a feature.** Being explicit that a field cannot be auto-generated is far better than emitting a plausible wrong value. The UI shows those fields highlighted and unfilled, which is exactly where a dev's manual input is worth something.
- **`include_probability`** on optional and nullable fields is what makes repeated generation produce genuinely different datasets rather than the same maximal payload every time.
- **Hash granularity.** Endpoint hash covers method, URI, auth, query config and the resolved input schema. Data object hash covers only its own properties. Both are needed: the first drives "this endpoint changed", the second drives "this DTO changed, here are the nine endpoints affected".
- **`diagnostics.unmapped_routes` is the CI lint.** `php artisan api:schema --fail-on-unmapped` uses the same extractor, so coverage cannot quietly rot as people add endpoints.
