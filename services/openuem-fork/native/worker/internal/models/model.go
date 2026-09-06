package models

import (
	"context"
	"database/sql"
	"fmt"

	"entgo.io/ent/dialect"
	entsql "entgo.io/ent/dialect/sql"
	_ "github.com/jackc/pgx/v5/stdlib"
	ent "github.com/open-uem/ent"
)

type Model struct {
	Client *ent.Client
	DB     *sql.DB
}

func New(dbUrl string) (*Model, error) {
	model := Model{}

	db, err := sql.Open("pgx", dbUrl)
	if err != nil {
		return nil, fmt.Errorf("could not connect with Postgres database: %v", err)
	}

	model.Client = ent.NewClient(ent.Driver(entsql.OpenDB(dialect.Postgres, db)))
	model.DB = db

	// RailTime fork: startup must never drop existing indexes or columns.
	// Ent's additive schema creation supports a new installation without an
	// environment-variable safety switch. Destructive changes need an explicit,
	// separately reviewed migration instead.
	ctx := context.Background()
	if err := model.Client.Schema.Create(ctx); err != nil {
		_ = model.Client.Close()
		return nil, err
	}

	return &model, nil
}

func (m *Model) Close() {
	m.Client.Close()
}
