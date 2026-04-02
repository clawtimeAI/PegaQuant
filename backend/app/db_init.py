from app.database import Base, engine
from app.models import OscillationStructure, TradeExecution, TradingAccount, User, UserAPIKey


def main():
    Base.metadata.create_all(bind=engine)


if __name__ == "__main__":
    main()
