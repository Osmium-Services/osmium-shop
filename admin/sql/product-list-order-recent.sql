ORDER BY GREATEST(COALESCE(P.updated_at, P.created_at), P.created_at, COALESCE(P.deleted_at, P.created_at)) DESC, P.id DESC
LIMIT 10
